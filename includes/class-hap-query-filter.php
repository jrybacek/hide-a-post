<?php
/**
 * WP_Query and direct-access filtering for Hide A Post.
 *
 * @package Hide_A_Post
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Excludes hidden categories and Administrator-authored default-category posts
 * from WP_Query results, wp-admin direct post access, and REST single-post reads
 * for non-Administrator users.
 *
 * @since 2.0.0
 */
final class HAP_Query_Filter {

	/**
	 * Cached Administrator user IDs per blog ID for the current request.
	 *
	 * @since 2.0.0
	 * @var array<int, int[]>
	 */
	private $admin_user_ids_by_blog = array();

	/**
	 * Re-entrancy guard to prevent recursive filtering when hooks inside
	 * `get_option()` or `get_users()` trigger nested queries.
	 *
	 * @since 2.0.0
	 * @var bool
	 */
	private $is_filtering = false;

	/**
	 * Registers query, capability, and REST API filter hooks.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'posts_where', array( $this, 'filter_posts_where' ), 10, 2 );
		add_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap' ), 10, 4 );
		add_filter( 'rest_prepare_post', array( $this, 'filter_rest_prepare_post' ), 10, 3 );
	}

	/**
	 * Appends parameterized NOT EXISTS clauses to hide matching posts from non-Administrators.
	 *
	 * @since 2.0.0
	 *
	 * @param string   $where Existing SQL WHERE clause.
	 * @param WP_Query $query Current WP_Query instance.
	 * @return string
	 */
	public function filter_posts_where( $where, $query ) {
		if ( $this->is_filtering || ! ( $query instanceof WP_Query ) ) {
			return $where;
		}

		$this->is_filtering = true;

		try {
			if ( ! $this->should_filter_query( $query ) ) {
				return $where;
			}

			$hidden_categories = HAP_Plugin::get_hidden_category_ids();
			if ( empty( $hidden_categories ) ) {
				return $where;
			}

			global $wpdb;

			$default_category_id = absint( get_option( 'default_category', 0 ) );
			if ( $default_category_id > 0 ) {
				$admin_ids = $this->get_administrator_ids();
				if ( ! empty( $admin_ids ) ) {
					$author_placeholders = implode( ', ', array_fill( 0, count( $admin_ids ), '%d' ) );
					$uncat_params        = array_merge( $admin_ids, array( $default_category_id ) );

					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic placeholder list built exclusively from integer count.
					$where .= (string) $wpdb->prepare(
						" AND NOT (
							{$wpdb->posts}.post_type = 'post'
							AND {$wpdb->posts}.post_author IN ({$author_placeholders})
							AND EXISTS (
								SELECT 1
								FROM {$wpdb->term_relationships} AS hap_tr_uncat
								INNER JOIN {$wpdb->term_taxonomy} AS hap_tt_uncat
									ON hap_tr_uncat.term_taxonomy_id = hap_tt_uncat.term_taxonomy_id
								WHERE hap_tr_uncat.object_id = {$wpdb->posts}.ID
									AND hap_tt_uncat.taxonomy = 'category'
									AND hap_tt_uncat.term_id = %d
							)
						)",
						$uncat_params
					);
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}

			$category_placeholders = implode( ', ', array_fill( 0, count( $hidden_categories ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic placeholder list built exclusively from integer count.
			$where .= (string) $wpdb->prepare(
				" AND NOT (
					{$wpdb->posts}.post_type = 'post'
					AND EXISTS (
						SELECT 1
						FROM {$wpdb->term_relationships} AS hap_tr
						INNER JOIN {$wpdb->term_taxonomy} AS hap_tt
							ON hap_tr.term_taxonomy_id = hap_tt.term_taxonomy_id
						WHERE hap_tr.object_id = {$wpdb->posts}.ID
							AND hap_tt.taxonomy = 'category'
							AND hap_tt.term_id IN ({$category_placeholders})
					)
				)",
				$hidden_categories
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} finally {
			$this->is_filtering = false;
		}

		return $where;
	}

	/**
	 * Prevents non-Administrators from directly reading, editing, or deleting hidden posts by ID.
	 *
	 * @since 2.0.0
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID being checked.
	 * @param mixed[]  $args    Additional capability arguments (typically post ID at index 0).
	 * @return string[]
	 */
	public function filter_map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( $this->is_filtering ) {
			return $caps;
		}

		if ( ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post' ), true ) ) {
			return $caps;
		}

		if ( empty( $args ) || ! isset( $args[0] ) ) {
			return $caps;
		}

		$post_id = HAP_Plugin::parse_positive_int( $args[0] );
		if ( 0 === $post_id ) {
			return $caps;
		}

		$this->is_filtering = true;

		try {
			if ( $this->is_bypassed_for_user( (int) $user_id ) ) {
				return $caps;
			}

			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && $this->is_post_hidden( $post ) ) {
				return array( 'do_not_allow' );
			}
		} finally {
			$this->is_filtering = false;
		}

		return $caps;
	}

	/**
	 * Blocks direct single-post REST API reads (`/wp/v2/posts/<id>`) for hidden posts
	 * when requested by non-Administrators.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Response                      $response The response object.
	 * @param WP_Post                               $post     Post object.
	 * @param WP_REST_Request<array<string, mixed>> $request  Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function filter_rest_prepare_post( $response, $post, $request ) {
		if ( $this->is_filtering || ! ( $post instanceof WP_Post ) ) {
			return $response;
		}

		if ( ! ( $request instanceof WP_REST_Request ) || empty( $request['id'] ) ) {
			return $response;
		}

		$this->is_filtering = true;

		try {
			if ( $this->is_bypassed_for_user( get_current_user_id() ) ) {
				return $response;
			}

			if ( $this->is_post_hidden( $post ) ) {
				return new WP_Error(
					'rest_post_invalid_id',
					__( 'Invalid post ID.', 'hide-a-post' ),
					array( 'status' => 404 )
				);
			}
		} finally {
			$this->is_filtering = false;
		}

		return $response;
	}

	/**
	 * Determines whether a specific post matches the plugin's hidden rules.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post $post Post object to evaluate.
	 * @return bool
	 */
	private function is_post_hidden( WP_Post $post ) {
		if ( 'post' !== $post->post_type ) {
			return false;
		}

		$hidden_categories = HAP_Plugin::get_hidden_category_ids();
		if ( empty( $hidden_categories ) ) {
			return false;
		}

		$raw_category_ids = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $raw_category_ids ) || empty( $raw_category_ids ) ) {
			return false;
		}

		$post_category_ids = array();
		foreach ( $raw_category_ids as $raw_term_id ) {
			$term_id = HAP_Plugin::parse_positive_int( $raw_term_id );
			if ( $term_id > 0 ) {
				$post_category_ids[] = $term_id;
			}
		}

		if ( empty( $post_category_ids ) ) {
			return false;
		}

		if ( ! empty( array_intersect( $post_category_ids, $hidden_categories ) ) ) {
			return true;
		}

		$default_category_id = absint( get_option( 'default_category', 0 ) );
		if ( $default_category_id > 0 && in_array( $default_category_id, $post_category_ids, true ) ) {
			$admin_ids = $this->get_administrator_ids();
			if ( in_array( (int) $post->post_author, $admin_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether filtering should be bypassed for the given user ID.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID (0 for anonymous or default WP-CLI context).
	 * @return bool
	 */
	private function is_bypassed_for_user( $user_id ) {
		if ( defined( 'WP_CLI' ) && WP_CLI && 0 === get_current_user_id() ) {
			return true;
		}

		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Determines whether a query should be filtered by Hide A Post.
	 *
	 * Bypasses filtering for:
	 * - Unauthenticated WP-CLI runs (default user ID 0) so commands like `wp post list` succeed cleanly.
	 * - Users with the `manage_options` capability (Administrators).
	 * - Queries that cannot return standard `post` objects (pages, nav menus, attachments, CPTs, etc.).
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Query $query Current WP_Query instance.
	 * @return bool
	 */
	private function should_filter_query( WP_Query $query ) {
		$should_filter = true;

		if ( $this->is_bypassed_for_user( get_current_user_id() ) ) {
			$should_filter = false;
		} elseif ( ! $this->query_targets_posts( $query ) ) {
			$should_filter = false;
		}

		/**
		 * Filters whether Hide A Post should filter the given WP_Query.
		 *
		 * @since 2.0.0
		 *
		 * @param bool     $should_filter Whether to filter the query.
		 * @param WP_Query $query         The current WP_Query instance.
		 */
		return (bool) apply_filters( 'hap_should_filter_query', $should_filter, $query );
	}

	/**
	 * Checks whether the given query can return items of the `post` post type.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Query $query Current WP_Query instance.
	 * @return bool
	 */
	private function query_targets_posts( WP_Query $query ) {
		$post_type = $query->get( 'post_type' );

		if ( '' === $post_type || null === $post_type || ( is_array( $post_type ) && empty( $post_type ) ) ) {
			return ! $query->is_page() && ! $query->is_attachment();
		}

		if ( is_string( $post_type ) ) {
			return 'post' === $post_type || 'any' === $post_type;
		}

		if ( is_array( $post_type ) ) {
			return in_array( 'post', $post_type, true ) || in_array( 'any', $post_type, true );
		}

		return false;
	}

	/**
	 * Retrieves and caches Administrator user IDs for the current blog in this request.
	 *
	 * @since 2.0.0
	 *
	 * @return int[]
	 */
	private function get_administrator_ids() {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( isset( $this->admin_user_ids_by_blog[ $blog_id ] ) ) {
			return $this->admin_user_ids_by_blog[ $blog_id ];
		}

		$raw_ids = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);

		$admin_ids = array();
		if ( is_array( $raw_ids ) ) {
			foreach ( $raw_ids as $raw_id ) {
				$user_id = HAP_Plugin::parse_positive_int( $raw_id );
				if ( $user_id > 0 ) {
					$admin_ids[] = $user_id;
				}
			}
		}

		$this->admin_user_ids_by_blog[ $blog_id ] = array_values( array_unique( $admin_ids ) );

		return $this->admin_user_ids_by_blog[ $blog_id ];
	}
}
