<?php
/**
 * Query, capability, REST API, comment, and link filtering for Hide A Post.
 *
 * @package Hide_A_Post
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hides posts assigned to the configured hidden categories (and posts authored
 * by Administrators in the default category) from users who cannot manage options.
 *
 * Every visibility path shares one SQL predicate built by
 * {@see HAP_Query_Filter::get_hidden_post_condition()}, so WP_Query, get_posts(),
 * comments, adjacent-post links, archives, REST, oEmbed, capabilities, and
 * canonical redirects all agree on which posts are hidden.
 *
 * @since 2.0.0
 */
final class HAP_Query_Filter {

	/**
	 * Object cache group used for computed hidden post IDs.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const CACHE_GROUP = 'hide-a-post';

	/**
	 * Administrator user IDs memoized per blog, user cache version, and super admin list.
	 *
	 * @since 2.0.0
	 * @var array<string, int[]>
	 */
	private $admin_ids_cache = array();

	/**
	 * Re-entrancy guard that prevents recursive filtering when hooks fired while
	 * evaluating visibility (options, users, capabilities, url_to_postid()) run
	 * nested queries.
	 *
	 * @since 2.0.0
	 * @var bool
	 */
	private $is_filtering = false;

	/**
	 * Post ID being saved by the current REST request (0 when none).
	 *
	 * @since 2.0.0
	 * @var int
	 */
	private $saving_post_id = 0;

	/**
	 * User ID performing the current REST save (0 when none).
	 *
	 * @since 2.0.0
	 * @var int
	 */
	private $saving_user_id = 0;

	/**
	 * REST request that owns the current save exemption, if any.
	 *
	 * @since 2.0.0
	 * @var WP_REST_Request<array<string, mixed>>|null
	 */
	private $saving_request = null;

	/**
	 * Registers all visibility filter hooks.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init() {
		// Post queries.
		add_filter( 'posts_where', array( $this, 'filter_posts_where' ), 10, 2 );
		// Runs last so it sees `suppress_filters` set by other pre_get_posts callbacks.
		add_action( 'pre_get_posts', array( $this, 'filter_unfiltered_query' ), PHP_INT_MAX );

		// Direct access by ID.
		add_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap' ), 10, 4 );
		add_filter( 'map_meta_cap', array( $this, 'filter_delete_term_cap' ), 10, 4 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'filter_rest_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'end_rest_save' ), 10, 3 );
		add_action( 'rest_insert_post', array( $this, 'track_rest_save_post' ), 1, 2 );
		add_action( 'pre_delete_term', array( $this, 'guard_hidden_category_deletion' ), 10, 2 );

		// Core admin screens and template tags that read posts by ID.
		add_filter( 'the_title', array( $this, 'filter_the_title' ), 10, 2 );
		add_filter( 'attachment_link', array( $this, 'filter_attachment_link' ), 10, 2 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_nav_menu_objects' ) );
		add_filter( 'rest_prepare_nav_menu_item', array( $this, 'filter_rest_menu_item' ), 10, 2 );
		add_filter( 'rest_prepare_wp_navigation', array( $this, 'filter_rest_navigation' ) );
		add_filter( 'wp_navigation_should_create_fallback', array( $this, 'filter_navigation_fallback_creation' ) );
		add_filter( 'render_block_core/navigation-link', array( $this, 'filter_navigation_link_block' ), 10, 2 );
		add_filter( 'render_block_core/navigation-submenu', array( $this, 'filter_navigation_submenu_block' ), 10, 3 );
		add_filter( 'block_core_navigation_render_inner_blocks', array( $this, 'redact_navigation_inner_blocks' ) );
		add_filter( 'render_block_data', array( $this, 'redact_top_level_submenu_data' ) );
		add_filter( 'register_block_type_args', array( $this, 'wrap_navigation_render_callback' ), 10, 2 );
		add_action( 'load-edit-comments.php', array( $this, 'guard_post_comments_screen' ) );
		add_action( 'load-edit.php', array( $this, 'limit_empty_trash_to_visible_posts' ) );
		add_filter( 'oembed_request_post_id', array( $this, 'filter_requested_post_id' ) );
		add_filter( 'old_slug_redirect_post_id', array( $this, 'filter_requested_post_id' ) );
		add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ) );

		// Comments.
		add_action( 'pre_get_comments', array( $this, 'filter_comment_query' ) );
		add_filter( 'comment_feed_where', array( $this, 'filter_comment_feed_where' ), 10, 2 );
		add_filter( 'comments_open', array( $this, 'filter_comments_open' ), 10, 2 );

		// Core SQL that bypasses WP_Query.
		add_filter( 'get_previous_post_where', array( $this, 'filter_adjacent_post_where' ) );
		add_filter( 'get_next_post_where', array( $this, 'filter_adjacent_post_where' ) );
		add_filter( 'getarchives_where', array( $this, 'filter_archives_where' ) );

		// Keep cached post queries in sync with category assignments.
		add_action( 'set_object_terms', array( $this, 'flush_post_query_cache_on_set_terms' ), 10, 4 );
		add_action( 'deleted_term_relationships', array( $this, 'flush_post_query_cache_on_delete_terms' ), 10, 3 );
	}

	/**
	 * Appends the hidden-post exclusion to filtered WP_Query requests.
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
			if ( $this->should_filter_query( $query ) ) {
				global $wpdb;

				$condition = $this->get_hidden_post_condition( $wpdb->posts );
				if ( '' !== $condition ) {
					$where .= ' AND NOT ' . $condition;
				}
			}
		} finally {
			$this->is_filtering = false;
		}

		return $where;
	}

	/**
	 * Excludes hidden posts from queries that run with `suppress_filters`
	 * (such as default get_posts() calls), which skip `posts_where`.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Query $query Current WP_Query instance.
	 * @return void
	 */
	public function filter_unfiltered_query( $query ) {
		if ( $this->is_filtering || ! ( $query instanceof WP_Query ) || ! $query->get( 'suppress_filters' ) ) {
			return;
		}

		$this->is_filtering = true;

		try {
			if ( ! $this->should_filter_query( $query ) ) {
				return;
			}

			$hidden_ids = $this->get_hidden_post_ids();
			if ( empty( $hidden_ids ) ) {
				return;
			}

			// WP_Query applies `p`, `post__in`, and `post__not_in` as an if/elseif
			// chain, so explicit IDs must be filtered directly.
			$single_id = absint( $query->get( 'p' ) );
			if ( $single_id > 0 ) {
				if ( in_array( $single_id, $hidden_ids, true ) ) {
					$query->set( 'p', 0 );
					$query->set( 'post__in', array( 0 ) );
				}
				return;
			}

			$post_in = wp_parse_id_list( $query->get( 'post__in' ) );
			if ( ! empty( $post_in ) ) {
				$remaining = array_values( array_diff( $post_in, $hidden_ids ) );
				$query->set( 'post__in', empty( $remaining ) ? array( 0 ) : $remaining );
				return;
			}

			$query->set(
				'post__not_in',
				array_values( array_unique( array_merge( wp_parse_id_list( $query->get( 'post__not_in' ) ), $hidden_ids ) ) )
			);
		} finally {
			$this->is_filtering = false;
		}
	}

	/**
	 * Prevents filtered users from reading, editing, publishing, or deleting hidden posts by ID.
	 *
	 * Meta capabilities derived from these (such as `edit_post_meta` and
	 * `edit_comment`) are covered because core maps them through `edit_post`.
	 *
	 * @since 2.0.0
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID being checked.
	 * @param mixed[]  $args    Additional capability arguments (post ID or WP_Post at index 0).
	 * @return string[]
	 */
	public function filter_map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( $this->is_filtering || ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post', 'publish_post' ), true ) ) {
			return $caps;
		}

		if ( ! is_array( $args ) || ! isset( $args[0] ) ) {
			return $caps;
		}

		$post_id = $args[0] instanceof WP_Post ? (int) $args[0]->ID : HAP_Plugin::parse_positive_int( $args[0] );
		if ( $post_id <= 0 ) {
			return $caps;
		}

		// The post was visible when this user's REST save started.
		if ( $post_id === $this->saving_post_id && (int) $user_id === $this->saving_user_id ) {
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
	 * Prevents filtered users from deleting a hidden category, which would
	 * reveal its posts and, if it is the last one, disable all filtering.
	 *
	 * @since 2.0.0
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID being checked.
	 * @param mixed[]  $args    Additional capability arguments (term ID at index 0).
	 * @return string[]
	 */
	public function filter_delete_term_cap( $caps, $cap, $user_id, $args ) {
		if ( 'delete_term' !== $cap || $this->is_filtering || ! is_array( $args ) || ! isset( $args[0] ) ) {
			return $caps;
		}

		$term_id = $this->to_id( $args[0] );
		if ( $term_id <= 0 || ! in_array( $term_id, HAP_Plugin::get_hidden_category_ids(), true ) ) {
			return $caps;
		}

		$this->is_filtering = true;

		try {
			if ( ! $this->is_bypassed_for_user( (int) $user_id ) ) {
				return array( 'do_not_allow' );
			}
		} finally {
			$this->is_filtering = false;
		}

		return $caps;
	}

	/**
	 * Stops filtered users from deleting a hidden category by any route,
	 * including core's bulk delete, which only checks `delete_terms`.
	 *
	 * Runs only for logged-in users, so cron and other system contexts can still
	 * delete terms programmatically.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $term_id  Term ID about to be deleted.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function guard_hidden_category_deletion( $term_id, $taxonomy ) {
		if ( 'category' !== $taxonomy || ! is_user_logged_in() || $this->is_filtering ) {
			return;
		}

		if ( ! in_array( $this->to_id( $term_id ), HAP_Plugin::get_hidden_category_ids(), true ) ) {
			return;
		}

		if ( $this->is_bypassed_for_user( get_current_user_id() ) ) {
			return;
		}

		wp_die(
			esc_html__( 'Sorry, you are not allowed to delete this category.', 'hide-a-post' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Replaces hidden post titles read by ID (admin screens such as the Media
	 * Library's parent column, theme template tags) for filtered users.
	 *
	 * @since 2.0.0
	 *
	 * @param string $title   Post title.
	 * @param mixed  $post_id Post ID.
	 * @return string
	 */
	public function filter_the_title( $title, $post_id = 0 ) {
		$post_id = $this->to_id( $post_id );
		if ( $post_id <= 0 || $this->is_filtering || $this->is_saving( $post_id ) ) {
			return $title;
		}

		return $this->is_hidden_for_current_user( $post_id ) ? __( '(Private post)', 'hide-a-post' ) : $title;
	}

	/**
	 * Keeps a hidden parent's permalink (and slug) out of attachment URLs.
	 *
	 * @since 2.0.0
	 *
	 * @param string $link    Attachment permalink.
	 * @param int    $post_id Attachment ID.
	 * @return string
	 */
	public function filter_attachment_link( $link, $post_id ) {
		$attachment = get_post( $this->to_id( $post_id ) );
		if ( ! ( $attachment instanceof WP_Post ) || $attachment->post_parent <= 0 || $this->is_filtering ) {
			return $link;
		}

		if ( $this->is_hidden_for_current_user( (int) $attachment->post_parent ) ) {
			return add_query_arg( 'attachment_id', (int) $attachment->ID, home_url( '/' ) );
		}

		return $link;
	}

	/**
	 * Removes classic menu items that point at hidden posts from wp_nav_menu()
	 * output only, leaving stored menu data untouched.
	 *
	 * @since 2.0.0
	 *
	 * @param object[] $items Sorted menu item objects.
	 * @return object[]
	 */
	public function filter_nav_menu_objects( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		return array_values(
			array_filter(
				$items,
				function ( $item ) {
					return ! $this->is_hidden_menu_item( $item );
				}
			)
		);
	}

	/**
	 * Redacts REST menu items (readable by any user with `edit_posts`) that
	 * point at hidden posts.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Response $response  Response object.
	 * @param object           $menu_item Menu item.
	 * @return WP_REST_Response
	 */
	public function filter_rest_menu_item( $response, $menu_item ) {
		if ( ! ( $response instanceof WP_REST_Response ) || ! $this->is_hidden_menu_item( $menu_item ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$data['url']         = '';
			$data['attr_title']  = '';
			$data['description'] = '';

			// Replace only the title fields the response context already includes.
			foreach ( array( 'raw', 'rendered' ) as $key ) {
				if ( isset( $data['title'][ $key ] ) ) {
					$data['title'][ $key ] = __( '(Private post)', 'hide-a-post' );
				}
			}
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Strips links to hidden posts from Navigation post content in REST
	 * responses (core exposes `content.raw` to visitors for `wp_navigation`).
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Response $response Response object.
	 * @return WP_REST_Response
	 */
	public function filter_rest_navigation( $response ) {
		if ( ! ( $response instanceof WP_REST_Response ) || ! $this->should_filter_for_current_user() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['content']['raw'] ) && is_string( $data['content']['raw'] ) ) {
			$data['content']['raw'] = serialize_blocks( $this->strip_hidden_navigation_links( parse_blocks( $data['content']['raw'] ) ) );
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Prevents a filtered visitor's page load from converting the classic menu
	 * into a saved Navigation post, which would persist filtered links and titles.
	 * An unfiltered user's request creates it with complete data.
	 *
	 * @since 2.0.0
	 *
	 * @param bool $create Whether to create a fallback Navigation post.
	 * @return bool
	 */
	public function filter_navigation_fallback_creation( $create ) {
		if ( $create && ! $this->is_filtering && $this->should_filter_for_current_user() ) {
			return false;
		}

		return (bool) $create;
	}

	/**
	 * Skips Navigation Link blocks that point at hidden posts.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $block_content Rendered block.
	 * @param array<string, mixed> $block         Parsed block.
	 * @return string
	 */
	public function filter_navigation_link_block( $block_content, $block ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		return $this->is_hidden_navigation_target( $attrs ) ? '' : $block_content;
	}

	/**
	 * Re-renders Navigation Submenu blocks that point at hidden posts without the
	 * post's label, URL, or description, keeping their visible child items.
	 *
	 * Uses the render filter because the Navigation block renders inner blocks
	 * through WP_Block::render(), which skips `render_block_data`.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $block_content Rendered block.
	 * @param array<string, mixed> $block         Parsed block.
	 * @param mixed                $instance      Block instance.
	 * @return string
	 */
	public function filter_navigation_submenu_block( $block_content, $block, $instance = null ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		// Core already suppressed it (e.g. unpublished target), so keep it suppressed.
		if ( '' === trim( (string) $block_content ) || ! $this->should_redact_submenu( $attrs ) ) {
			return $block_content;
		}

		if ( ! ( $instance instanceof WP_Block ) ) {
			return '';
		}

		// Redact every hidden submenu in the subtree at once, so the copy renders
		// a single time without triggering further re-renders below it.
		$redacted = new WP_Block( $this->redact_hidden_submenus( $instance->parsed_block ), is_array( $instance->context ) ? $instance->context : array() );

		return (string) $redacted->render();
	}

	/**
	 * Redacts hidden-post Navigation Submenu blocks before the Navigation block
	 * renders its items, so each item renders once.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $inner_blocks Navigation inner blocks (WP_Block_List).
	 * @return mixed
	 */
	public function redact_navigation_inner_blocks( $inner_blocks ) {
		if ( $inner_blocks instanceof WP_Block_List ) {
			$this->redact_block_list( $inner_blocks );
		}

		return $inner_blocks;
	}

	/**
	 * Redacts hidden-post submenus in a top-level Navigation Submenu block's
	 * tree before it is built, so render_block() renders it once.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return array<string, mixed>
	 */
	public function redact_top_level_submenu_data( $parsed_block ) {
		if ( is_array( $parsed_block ) && isset( $parsed_block['blockName'] ) && 'core/navigation-submenu' === $parsed_block['blockName'] ) {
			return $this->redact_hidden_submenus( $parsed_block );
		}

		return $parsed_block;
	}

	/**
	 * Wraps the Navigation block's render callback so a `ref` that points at a
	 * hidden post (core does not check that `ref` is a `wp_navigation` post)
	 * renders nothing, including through the REST block renderer.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $args       Block type registration arguments.
	 * @param string               $block_type Block type name.
	 * @return array<string, mixed>
	 */
	public function wrap_navigation_render_callback( $args, $block_type ) {
		if ( 'core/navigation' !== $block_type || ! is_array( $args ) || ! isset( $args['render_callback'] ) || ! is_callable( $args['render_callback'] ) ) {
			return $args;
		}

		$original                = $args['render_callback'];
		$args['render_callback'] = function ( $attributes, $content = '', $block = null ) use ( $original ) {
			if ( is_array( $attributes ) && isset( $attributes['ref'] ) && $this->is_hidden_for_current_user( $this->to_id( $attributes['ref'] ) ) ) {
				return '';
			}

			return call_user_func( $original, $attributes, $content, $block );
		};

		return $args;
	}

	/**
	 * Recursively redacts hidden-post submenus in a WP_Block_List in place.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Block_List $block_list Block list.
	 * @return void
	 */
	private function redact_block_list( WP_Block_List $block_list ) {
		foreach ( $block_list as $block ) {
			if ( ! ( $block instanceof WP_Block ) ) {
				continue;
			}

			$attrs = isset( $block->parsed_block['attrs'] ) && is_array( $block->parsed_block['attrs'] ) ? $block->parsed_block['attrs'] : array();
			if ( 'core/navigation-submenu' === $block->name && $this->should_redact_submenu( $attrs ) ) {
				$block->parsed_block['attrs'] = $this->redact_navigation_attrs( $attrs );
				// WP_Block computes `attributes` lazily from parsed_block on next access.
				unset( $block->attributes );
			}

			if ( $block->inner_blocks instanceof WP_Block_List ) {
				$this->redact_block_list( $block->inner_blocks );
			}
		}
	}

	/**
	 * Returns a parsed block with every hidden-post submenu in its tree redacted.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	private function redact_hidden_submenus( array $block ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		if ( isset( $block['blockName'] ) && 'core/navigation-submenu' === $block['blockName'] && $this->should_redact_submenu( $attrs ) ) {
			$block['attrs'] = $this->redact_navigation_attrs( $attrs );
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $index => $child ) {
				if ( is_array( $child ) ) {
					$block['innerBlocks'][ $index ] = $this->redact_hidden_submenus( $child );
				}
			}
		}

		return $block;
	}

	/**
	 * Removes Navigation Link blocks and redacts Navigation Submenu blocks that
	 * point at hidden posts, recursively.
	 *
	 * @since 2.0.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function strip_hidden_navigation_links( array $blocks ) {
		$result = array();

		foreach ( $blocks as $block ) {
			$kept = is_array( $block ) ? $this->strip_navigation_block( $block ) : $block;
			if ( null !== $kept ) {
				$result[] = $kept;
			}
		}

		return $result;
	}

	/**
	 * Processes one parsed block for {@see HAP_Query_Filter::strip_hidden_navigation_links()},
	 * keeping `innerContent` placeholders aligned with the remaining `innerBlocks`.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>|null Processed block, or null to remove it.
	 */
	private function strip_navigation_block( array $block ) {
		$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && $this->is_hidden_navigation_target( $attrs ) ) {
			if ( 'core/navigation-link' === $name ) {
				return null;
			}

			$block['attrs'] = $this->redact_navigation_attrs( $attrs );
		}

		if ( empty( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
			return $block;
		}

		$inner_blocks = array();
		$kept_flags   = array();
		foreach ( $block['innerBlocks'] as $child ) {
			$kept         = is_array( $child ) ? $this->strip_navigation_block( $child ) : $child;
			$kept_flags[] = null !== $kept;
			if ( null !== $kept ) {
				$inner_blocks[] = $kept;
			}
		}

		// Each null in innerContent is the placeholder for the next inner block.
		$inner_content = array();
		$index         = 0;
		foreach ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array() as $chunk ) {
			if ( null === $chunk ) {
				$keep = isset( $kept_flags[ $index ] ) ? $kept_flags[ $index ] : true;
				++$index;
				if ( ! $keep ) {
					continue;
				}
			}
			$inner_content[] = $chunk;
		}

		$block['innerBlocks']  = $inner_blocks;
		$block['innerContent'] = $inner_content;

		return $block;
	}

	/**
	 * Removes a hidden post's identity from Navigation block attributes.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return array<string, mixed>
	 */
	private function redact_navigation_attrs( array $attrs ) {
		unset( $attrs['id'], $attrs['url'], $attrs['kind'], $attrs['type'], $attrs['title'], $attrs['description'] );
		$attrs['label'] = __( '(Private post)', 'hide-a-post' );

		return $attrs;
	}

	/**
	 * Checks whether Navigation block attributes point at a hidden post,
	 * using the same post-link test as core (`kind` or `type`).
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return bool
	 */
	private function is_hidden_navigation_target( array $attrs ) {
		if ( ! isset( $attrs['id'] ) ) {
			return false;
		}

		$is_post_link = ( isset( $attrs['kind'] ) && 'post-type' === $attrs['kind'] ) || ( isset( $attrs['type'] ) && 'post' === $attrs['type'] );

		return $is_post_link && $this->is_hidden_for_current_user( $this->to_id( $attrs['id'] ) );
	}

	/**
	 * Checks whether a Navigation Submenu should be rendered redacted: it points
	 * at a hidden post that is published. Core already suppresses submenus whose
	 * post is not published, so those are left for core to drop.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return bool
	 */
	private function should_redact_submenu( array $attrs ) {
		return $this->is_hidden_navigation_target( $attrs ) && 'publish' === get_post_status( $this->to_id( $attrs['id'] ) );
	}

	/**
	 * Checks whether a classic menu item points at a post hidden from the current user.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $item Menu item object.
	 * @return bool
	 */
	private function is_hidden_menu_item( $item ) {
		return is_object( $item )
			&& isset( $item->type, $item->object, $item->object_id )
			&& 'post_type' === $item->type
			&& 'post' === $item->object
			&& $this->is_hidden_for_current_user( $this->to_id( $item->object_id ) );
	}

	/**
	 * Checks whether the given post ID is the post being saved by the current user's REST request.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_saving( $post_id ) {
		return $post_id > 0 && $post_id === $this->saving_post_id && get_current_user_id() === $this->saving_user_id;
	}

	/**
	 * Checks whether a post ID is hidden from the current user.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_hidden_for_current_user( $post_id ) {
		if ( $post_id <= 0 || $this->is_filtering ) {
			return false;
		}

		$this->is_filtering = true;

		try {
			$post = get_post( $post_id );

			return $post instanceof WP_Post && $this->should_filter_for_current_user() && $this->is_post_hidden( $post );
		} finally {
			$this->is_filtering = false;
		}
	}

	/**
	 * Blocks the post-scoped Comments screen (`edit-comments.php?p=<id>`), which
	 * prints the post's title and permalink, for hidden posts.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function guard_post_comments_screen() {
		// Parse exactly as WP_Comments_List_Table does (absint), so negative and
		// array values resolve to the same post core renders.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen parameter.
		$post_id = isset( $_REQUEST['p'] ) ? absint( wp_unslash( $_REQUEST['p'] ) ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post instanceof WP_Post && $this->should_filter_for_current_user() && $this->is_post_hidden( $post ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to view this item.', 'hide-a-post' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Turns "Empty Trash" into a bulk delete of the posts the user can see.
	 *
	 * Core collects every trashed post with direct SQL and stops at the first one
	 * the user cannot delete, after deleting the ones before it. Hidden trashed
	 * posts are left for an Administrator.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function limit_empty_trash_to_visible_posts() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- edit.php verifies the bulk-posts nonce after this hook.
		if ( ! isset( $_REQUEST['delete_all'] ) && ! isset( $_REQUEST['delete_all2'] ) ) {
			return;
		}

		global $typenow;

		// admin.php resolves the screen's post type (falling back to `post`).
		$post_type   = is_string( $typenow ) && '' !== $typenow ? $typenow : 'post';
		$post_status = isset( $_REQUEST['post_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_status'] ) ) : '';

		// Only the Trash view offers "Empty Trash"; leave anything else to core.
		if ( 'post' !== $post_type || 'trash' !== $post_status || ! $this->should_filter_for_current_user() ) {
			return;
		}

		$visible_ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'trash',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'suppress_filters' => false,
			)
		);

		// edit.php reads `media` and `ids` before `post`.
		foreach ( array( 'delete_all', 'delete_all2', 'media', 'ids' ) as $key ) {
			unset( $_REQUEST[ $key ], $_GET[ $key ], $_POST[ $key ] );
		}

		$_REQUEST['action'] = 'delete';
		$_REQUEST['post']   = array_map( 'strval', array_map( 'intval', $visible_ids ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Binds the save exemption to the post created or updated by the tracked REST request.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post                               $post    Inserted or updated post.
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return void
	 */
	public function track_rest_save_post( $post, $request ) {
		if ( null !== $this->saving_request && $request === $this->saving_request && $post instanceof WP_Post ) {
			$this->saving_post_id = (int) $post->ID;
		}
	}

	/**
	 * Ends the save exemption when the request that started it finishes.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed                $response Result to send to the client.
	 * @param array<string, mixed> $handler  Route handler used for the request.
	 * @param mixed                $request  Request used to generate the response.
	 * @return mixed
	 */
	public function end_rest_save( $response, $handler = array(), $request = null ) {
		if ( null !== $this->saving_request && $request === $this->saving_request ) {
			$this->saving_request = null;
			$this->saving_post_id = 0;
			$this->saving_user_id = 0;
		}

		return $response;
	}

	/**
	 * Rejects REST requests for hidden posts (and their sub-resources) and for
	 * comments on hidden posts with a 404 before the endpoint callback runs.
	 *
	 * Runs before permission callbacks, so a filtered user who has just assigned
	 * a hidden category still receives a normal response for that save.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed                                 $response Result to send to the client; null unless short-circuited.
	 * @param array<string, mixed>                  $handler  Route handler used for the request.
	 * @param WP_REST_Request<array<string, mixed>> $request  Request used to generate the response.
	 * @return mixed
	 */
	public function filter_rest_request( $response, $handler, $request ) {
		if ( null !== $response || $this->is_filtering || ! ( $request instanceof WP_REST_Request ) ) {
			return $response;
		}

		$route   = (string) $request->get_route();
		$is_read = in_array( $request->get_method(), array( 'GET', 'HEAD' ), true );

		$this->is_filtering = true;

		try {
			// Core endpoints that take a `post` (comments, terms) must not act on
			// or describe a hidden post.
			if ( 1 === preg_match( '#^/wp/v2/#i', $route ) ) {
				$post_param = $request->get_param( 'post' );
				foreach ( is_array( $post_param ) ? $post_param : array( $post_param ) as $candidate ) {
					$candidate_id = $this->to_id( $candidate );
					$post         = $candidate_id > 0 ? get_post( $candidate_id ) : null;
					if ( $post instanceof WP_Post && $this->should_filter_for_current_user() && $this->is_post_hidden( $post ) ) {
						return new WP_Error(
							'rest_post_invalid_id',
							__( 'Invalid post ID.', 'hide-a-post' ),
							array( 'status' => 404 )
						);
					}
				}
			}

			$posts_base = rest_get_route_for_post_type_items( 'post' );

			// A save that starts on a visible post (or creates one) may assign a
			// hidden category; let it finish its own meta and term writes. The
			// exemption is bound to this request and the post it saves.
			if ( ! $is_read && null === $this->saving_request && '' !== $posts_base && 1 === preg_match( '#^' . preg_quote( untrailingslashit( $posts_base ), '#' ) . '(?:/|$)#i', $route ) ) {
				$this->saving_request = $request;
				$this->saving_user_id = get_current_user_id();
				$this->saving_post_id = 0;
			}

			// Query and body `id`/`parent` values take precedence over URL
			// parameters in WP_REST_Request, so check every candidate ID on any
			// matched item route (including `/0`), cast the way core casts them.
			$route_post_id = $this->match_route_id( $route, $posts_base );
			if ( null !== $route_post_id ) {
				$candidates = array( $route_post_id, $request->get_param( 'id' ), $request->get_param( 'parent' ) );
				foreach ( $candidates as $candidate ) {
					$candidate_id = $this->to_id( $candidate );
					$post         = $candidate_id > 0 ? get_post( $candidate_id ) : null;
					if ( $post instanceof WP_Post && $this->should_filter_for_current_user() && $this->is_post_hidden( $post ) ) {
						$this->end_rest_save( null, array(), $request );

						return new WP_Error(
							'rest_post_invalid_id',
							__( 'Invalid post ID.', 'hide-a-post' ),
							array( 'status' => 404 )
						);
					}
				}

				// Every candidate is visible, so the update target may be exempted now.
				if ( $request === $this->saving_request ) {
					$target_id            = $this->to_id( $request->get_param( 'id' ) );
					$this->saving_post_id = $target_id > 0 ? $target_id : (int) $route_post_id;
				}

				return $response;
			}

			$route_comment_id = $this->match_route_id( $route, '/wp/v2/comments' );
			if ( null !== $route_comment_id ) {
				foreach ( array( $route_comment_id, $request->get_param( 'id' ) ) as $candidate ) {
					$candidate_id = $this->to_id( $candidate );
					$comment      = $candidate_id > 0 ? get_comment( $candidate_id ) : null;
					$post         = $comment instanceof WP_Comment ? get_post( (int) $comment->comment_post_ID ) : null;
					if ( $post instanceof WP_Post && $this->should_filter_for_current_user() && $this->is_post_hidden( $post ) ) {
						return new WP_Error(
							'rest_comment_invalid_id',
							__( 'Invalid comment ID.', 'hide-a-post' ),
							array( 'status' => 404 )
						);
					}
				}
			}
		} finally {
			$this->is_filtering = false;
		}

		return $response;
	}

	/**
	 * Prevents the oEmbed endpoint and old-slug redirects from resolving hidden posts.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id Post ID resolved from the requested URL or old slug.
	 * @return int
	 */
	public function filter_requested_post_id( $post_id ) {
		$post_id = HAP_Plugin::parse_positive_int( $post_id );
		if ( 0 === $post_id || $this->is_filtering ) {
			return $post_id;
		}

		$this->is_filtering = true;

		try {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && $this->should_filter_for_current_user() && $this->is_post_hidden( $post ) ) {
				return 0;
			}
		} finally {
			$this->is_filtering = false;
		}

		return $post_id;
	}

	/**
	 * Cancels canonical redirects (including `?p=<id>` and 404 slug guessing)
	 * that would reveal a hidden post's permalink.
	 *
	 * @since 2.0.0
	 *
	 * @param string|false $redirect_url Redirect URL, or false to cancel.
	 * @return string|false
	 */
	public function filter_redirect_canonical( $redirect_url ) {
		if ( ! is_string( $redirect_url ) || '' === $redirect_url || $this->is_filtering ) {
			return $redirect_url;
		}

		$this->is_filtering = true;

		try {
			if ( ! $this->should_filter_for_current_user() ) {
				return $redirect_url;
			}

			// The guard is active, so url_to_postid() resolves hidden posts too.
			// Skip 0: get_post( 0 ) falls back to the global $post.
			$post_id = url_to_postid( $redirect_url );
			$post    = $post_id > 0 ? get_post( $post_id ) : null;
			if ( $post instanceof WP_Post && $this->is_post_hidden( $post ) ) {
				return false;
			}
		} finally {
			$this->is_filtering = false;
		}

		return $redirect_url;
	}

	/**
	 * Excludes comments on hidden posts from WP_Comment_Query results.
	 *
	 * Uses the `post__not_in` query var (rather than SQL clauses) so the
	 * exclusion is part of WP_Comment_Query's cache key.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Comment_Query $query Current comment query.
	 * @return void
	 */
	public function filter_comment_query( $query ) {
		if ( $this->is_filtering || ! ( $query instanceof WP_Comment_Query ) ) {
			return;
		}

		$this->is_filtering = true;

		try {
			if ( ! $this->should_filter_for_current_user() ) {
				return;
			}

			$hidden_ids = $this->get_hidden_post_ids();
			if ( empty( $hidden_ids ) ) {
				return;
			}

			$existing = isset( $query->query_vars['post__not_in'] ) ? wp_parse_id_list( $query->query_vars['post__not_in'] ) : array();

			$query->query_vars['post__not_in'] = array_values( array_unique( array_merge( $existing, $hidden_ids ) ) );
		} finally {
			$this->is_filtering = false;
		}
	}

	/**
	 * Excludes comments on hidden posts from the site-wide comments feed.
	 *
	 * @since 2.0.0
	 *
	 * @param string   $where Comment feed WHERE clause.
	 * @param WP_Query $query Query that produced the feed.
	 * @return string
	 */
	public function filter_comment_feed_where( $where, $query = null ) {
		global $wpdb;

		// Singular comment feeds do not join the posts table, and their post
		// has already passed the visibility filter.
		if ( $query instanceof WP_Query && $query->is_singular() ) {
			return $where;
		}

		return $this->append_condition( $where, $wpdb->posts );
	}

	/**
	 * Closes comments on hidden posts for filtered users (front end and REST).
	 *
	 * @since 2.0.0
	 *
	 * @param bool $open    Whether comments are open.
	 * @param int  $post_id Post ID.
	 * @return bool
	 */
	public function filter_comments_open( $open, $post_id ) {
		if ( ! $open || $this->is_filtering ) {
			return (bool) $open;
		}

		$this->is_filtering = true;

		try {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && $this->should_filter_for_current_user() && $this->is_post_hidden( $post ) ) {
				return false;
			}
		} finally {
			$this->is_filtering = false;
		}

		return true;
	}

	/**
	 * Skips hidden posts in previous/next post links.
	 *
	 * @since 2.0.0
	 *
	 * @param string $where Adjacent post WHERE clause (posts table aliased as `p`).
	 * @return string
	 */
	public function filter_adjacent_post_where( $where ) {
		return $this->append_condition( $where, 'p' );
	}

	/**
	 * Excludes hidden posts from wp_get_archives() output and counts.
	 *
	 * @since 2.0.0
	 *
	 * @param string $where Archives WHERE clause.
	 * @return string
	 */
	public function filter_archives_where( $where ) {
		global $wpdb;

		return $this->append_condition( $where, $wpdb->posts );
	}

	/**
	 * Invalidates cached post queries when category assignments change.
	 *
	 * WP_Query only adds the terms cache version to its cache key for native
	 * tax queries, so category-only changes would otherwise serve stale results.
	 *
	 * @since 2.0.0
	 *
	 * @param int                   $object_id Object ID.
	 * @param array<int|string>|int $terms     Terms passed to wp_set_object_terms().
	 * @param int[]                 $tt_ids    Term taxonomy IDs.
	 * @param string                $taxonomy  Taxonomy slug.
	 * @return void
	 */
	public function flush_post_query_cache_on_set_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'category' === $taxonomy ) {
			wp_cache_set_posts_last_changed();
		}
	}

	/**
	 * Invalidates cached post queries when category relationships are removed.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $object_id Object ID.
	 * @param int[]  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 * @return void
	 */
	public function flush_post_query_cache_on_delete_terms( $object_id, $tt_ids, $taxonomy ) {
		if ( 'category' === $taxonomy ) {
			wp_cache_set_posts_last_changed();
		}
	}

	/**
	 * Appends `AND NOT <hidden condition>` to a WHERE clause for filtered users.
	 *
	 * @since 2.0.0
	 *
	 * @param string $where      Existing WHERE clause.
	 * @param string $post_alias Trusted posts table name or alias used in the query.
	 * @return string
	 */
	private function append_condition( $where, $post_alias ) {
		if ( $this->is_filtering ) {
			return $where;
		}

		$this->is_filtering = true;

		try {
			if ( $this->should_filter_for_current_user() ) {
				$condition = $this->get_hidden_post_condition( $post_alias );
				if ( '' !== $condition ) {
					$where .= ' AND NOT ' . $condition;
				}
			}
		} finally {
			$this->is_filtering = false;
		}

		return $where;
	}

	/**
	 * Builds a prepared SQL boolean expression that is true for hidden posts.
	 *
	 * @since 2.0.0
	 *
	 * @param string $post_alias Trusted posts table name or alias (never user input).
	 * @return string Prepared SQL expression, or an empty string when nothing is hidden.
	 */
	private function get_hidden_post_condition( $post_alias ) {
		global $wpdb;

		$hidden_categories = HAP_Plugin::get_hidden_category_ids();
		if ( empty( $hidden_categories ) ) {
			return '';
		}

		$category_placeholders = implode( ', ', array_fill( 0, count( $hidden_categories ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Trusted table alias and placeholder lists built from integer counts.
		$conditions = array(
			(string) $wpdb->prepare(
				"EXISTS (
					SELECT 1
					FROM {$wpdb->term_relationships} AS hap_tr
					INNER JOIN {$wpdb->term_taxonomy} AS hap_tt
						ON hap_tr.term_taxonomy_id = hap_tt.term_taxonomy_id
					WHERE hap_tr.object_id = {$post_alias}.ID
						AND hap_tt.taxonomy = 'category'
						AND hap_tt.term_id IN ({$category_placeholders})
				)",
				$hidden_categories
			),
		);

		$default_category_id = $this->get_hidden_default_category_id( $hidden_categories );
		$admin_ids           = $default_category_id > 0 ? $this->get_administrator_ids() : array();

		if ( ! empty( $admin_ids ) ) {
			$author_placeholders = implode( ', ', array_fill( 0, count( $admin_ids ), '%d' ) );

			$conditions[] = (string) $wpdb->prepare(
				"(
					{$post_alias}.post_author IN ({$author_placeholders})
					AND EXISTS (
						SELECT 1
						FROM {$wpdb->term_relationships} AS hap_tr_default
						INNER JOIN {$wpdb->term_taxonomy} AS hap_tt_default
							ON hap_tr_default.term_taxonomy_id = hap_tt_default.term_taxonomy_id
						WHERE hap_tr_default.object_id = {$post_alias}.ID
							AND hap_tt_default.taxonomy = 'category'
							AND hap_tt_default.term_id = %d
					)
				)",
				array_merge( $admin_ids, array( $default_category_id ) )
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return "( {$post_alias}.post_type = 'post' AND ( " . implode( ' OR ', $conditions ) . ' ) )';
	}

	/**
	 * Returns the IDs of all hidden posts, cached against post, term, and rule changes.
	 *
	 * @since 2.0.0
	 *
	 * @return int[]
	 */
	private function get_hidden_post_ids() {
		global $wpdb;

		$hidden_categories = HAP_Plugin::get_hidden_category_ids();
		if ( empty( $hidden_categories ) ) {
			return array();
		}

		$default_category_id = $this->get_hidden_default_category_id( $hidden_categories );
		$admin_ids           = $default_category_id > 0 ? $this->get_administrator_ids() : array();

		$cache_key = sprintf(
			'hidden_post_ids:%s:%s:%s',
			md5( (string) wp_json_encode( array( $hidden_categories, $default_category_id, $admin_ids ) ) ),
			wp_cache_get_last_changed( 'posts' ),
			wp_cache_get_last_changed( 'terms' )
		);

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$condition = $this->get_hidden_post_condition( 'hap_p' );
		if ( '' === $condition ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Condition is fully prepared above; results are cached.
		$raw_ids = $wpdb->get_col( "SELECT hap_p.ID FROM {$wpdb->posts} AS hap_p WHERE {$condition}" );

		$hidden_ids = array();
		foreach ( (array) $raw_ids as $raw_id ) {
			$post_id = HAP_Plugin::parse_positive_int( $raw_id );
			if ( $post_id > 0 ) {
				$hidden_ids[] = $post_id;
			}
		}

		// Keys change with every post or term edit, so expire obsolete lists.
		wp_cache_set( $cache_key, $hidden_ids, self::CACHE_GROUP, DAY_IN_SECONDS );

		return $hidden_ids;
	}

	/**
	 * Determines whether a specific post matches the hidden rules.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post $post Post object to evaluate.
	 * @return bool
	 */
	private function is_post_hidden( WP_Post $post ) {
		// Core maps revision capabilities to the parent post inline, without
		// re-running map_meta_cap, so resolve revisions here.
		if ( 'revision' === $post->post_type && $post->post_parent > 0 ) {
			$parent = get_post( (int) $post->post_parent );
			if ( ! ( $parent instanceof WP_Post ) ) {
				return false;
			}
			$post = $parent;
		}

		if ( 'post' !== $post->post_type ) {
			return false;
		}

		return in_array( (int) $post->ID, $this->get_hidden_post_ids(), true );
	}

	/**
	 * Returns the default category ID when the Administrator default-category rule applies.
	 *
	 * @since 2.0.0
	 *
	 * @param int[] $hidden_categories Hidden category IDs.
	 * @return int Default category ID, or 0 when the rule is disabled or redundant.
	 */
	private function get_hidden_default_category_id( array $hidden_categories ) {
		$default_category_id = absint( get_option( 'default_category', 0 ) );

		// Already hidden for everyone via the category rule.
		if ( $default_category_id <= 0 || in_array( $default_category_id, $hidden_categories, true ) ) {
			return 0;
		}

		/**
		 * Filters whether Administrator-authored posts in the default category are hidden.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $hide                Whether to hide Administrator default-category posts. Default true.
		 * @param int  $default_category_id The site's default category ID.
		 */
		$hide = (bool) apply_filters( 'hap_hide_admin_default_category_posts', true, $default_category_id );

		return $hide ? $default_category_id : 0;
	}

	/**
	 * Checks whether the current user is subject to filtering and anything is hidden.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	private function should_filter_for_current_user() {
		return ! empty( HAP_Plugin::get_hidden_category_ids() ) && ! $this->is_bypassed_for_user( get_current_user_id() );
	}

	/**
	 * Checks whether the given user may see hidden posts.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID (0 for anonymous or default WP-CLI context).
	 * @return bool
	 */
	private function is_bypassed_for_user( $user_id ) {
		$user_id  = (int) $user_id;
		$bypassed = false;

		if ( 0 === $user_id && defined( 'WP_CLI' ) && WP_CLI ) {
			$bypassed = true;
		} elseif ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			$bypassed = true;
		}

		/**
		 * Filters whether a user can see posts hidden by Hide A Post.
		 *
		 * Applies to every visibility path (queries, capabilities, REST, comments, links).
		 *
		 * @since 2.0.0
		 *
		 * @param bool $bypassed Whether the user bypasses filtering.
		 * @param int  $user_id  User ID being checked (0 for anonymous).
		 */
		return (bool) apply_filters( 'hap_user_can_view_hidden_posts', $bypassed, $user_id );
	}

	/**
	 * Determines whether a WP_Query should be filtered.
	 *
	 * Bypasses filtering for:
	 * - Unauthenticated WP-CLI runs (user ID 0) so commands like `wp post list` succeed cleanly.
	 * - Users with the `manage_options` capability (Administrators).
	 * - Queries that cannot return standard `post` objects (pages, nav menus, attachments, CPTs, etc.).
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Query $query Current WP_Query instance.
	 * @return bool
	 */
	private function should_filter_query( WP_Query $query ) {
		$should_filter = $this->should_filter_for_current_user() && $this->query_targets_posts( $query );

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
	 * Extracts a numeric ID from a REST route under the given collection base.
	 *
	 * Matches `<base>/<id>` and any sub-route such as `<base>/<id>/revisions`.
	 *
	 * @since 2.0.0
	 *
	 * @param string $route REST route being requested.
	 * @param string $base  Collection route base, e.g. `/wp/v2/posts`.
	 * @return int|null Matched ID (possibly 0), or null when the route does not match.
	 */
	private function match_route_id( $route, $base ) {
		if ( '' === $base ) {
			return null;
		}

		// Case-insensitive, like WP_REST_Server::match_request_to_handler().
		if ( 1 === preg_match( '#^' . preg_quote( untrailingslashit( $base ), '#' ) . '/(\d+)(?:/|$)#i', $route, $matches ) ) {
			return $this->to_id( $matches[1] );
		}

		return null;
	}

	/**
	 * Casts a request value to an ID the way core's get_post()/get_comment() do.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $value Candidate ID.
	 * @return int ID, or 0 for non-scalar or non-positive values.
	 */
	private function to_id( $value ) {
		$id = is_scalar( $value ) ? (int) $value : 0;

		return $id > 0 ? $id : 0;
	}

	/**
	 * Retrieves the IDs of users treated as Administrators for the current blog.
	 *
	 * Uses the same `manage_options` capability as the visibility bypass and
	 * includes network Super Admins on Multisite.
	 *
	 * @since 2.0.0
	 *
	 * @return int[]
	 */
	private function get_administrator_ids() {
		$super_admins = is_multisite() ? get_super_admins() : array();
		$memo_key     = get_current_blog_id() . ':' . wp_cache_get_last_changed( 'users' ) . ':' . md5( implode( ',', $super_admins ) );

		if ( isset( $this->admin_ids_cache[ $memo_key ] ) ) {
			return $this->admin_ids_cache[ $memo_key ];
		}

		$raw_ids = get_users(
			array(
				'capability' => 'manage_options',
				'fields'     => 'ID',
			)
		);
		$raw_ids = is_array( $raw_ids ) ? $raw_ids : array();

		foreach ( $super_admins as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof WP_User ) {
				$raw_ids[] = $user->ID;
			}
		}

		/**
		 * Filters the user IDs whose default-category posts are hidden.
		 *
		 * @since 2.0.0
		 *
		 * @param array<int|string> $raw_ids User IDs with `manage_options` (plus Super Admins on Multisite).
		 */
		$raw_ids = apply_filters( 'hap_administrator_ids', $raw_ids );

		$admin_ids = array();
		foreach ( is_array( $raw_ids ) ? $raw_ids : array() as $raw_id ) {
			$user_id = HAP_Plugin::parse_positive_int( $raw_id );
			if ( $user_id > 0 ) {
				$admin_ids[] = $user_id;
			}
		}

		$admin_ids = array_values( array_unique( $admin_ids ) );
		sort( $admin_ids );

		$this->admin_ids_cache[ $memo_key ] = $admin_ids;

		return $admin_ids;
	}
}
