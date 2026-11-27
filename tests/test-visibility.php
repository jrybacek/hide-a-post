<?php
/**
 * Integration tests for Hide A Post visibility rules.
 *
 * @package Hide_A_Post
 */

/**
 * Covers every visibility path filtered by HAP_Query_Filter.
 */
class HAP_Test_Visibility extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Hidden category ID.
	 *
	 * @var int
	 */
	private $secret_cat;

	/**
	 * Visible category ID.
	 *
	 * @var int
	 */
	private $public_cat;

	/**
	 * Default category ID.
	 *
	 * @var int
	 */
	private $default_cat;

	/**
	 * Fixture post IDs keyed by label, in ascending date order.
	 *
	 * @var array<string, int>
	 */
	private $posts = array();

	/**
	 * Creates users, categories, and posts, then hides the Secret category.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->secret_cat  = self::factory()->category->create( array( 'name' => 'Secret' ) );
		$this->public_cat  = self::factory()->category->create( array( 'name' => 'Public' ) );
		$this->default_cat = (int) get_option( 'default_category' );

		$fixtures = array(
			'admin_public'   => array( $this->admin_id, $this->public_cat ),
			'admin_secret'   => array( $this->admin_id, $this->secret_cat ),
			'editor_secret'  => array( $this->editor_id, $this->secret_cat ),
			'admin_default'  => array( $this->admin_id, $this->default_cat ),
			'editor_default' => array( $this->editor_id, $this->default_cat ),
			'editor_public'  => array( $this->editor_id, $this->public_cat ),
		);

		$day = 1;
		foreach ( $fixtures as $label => $fixture ) {
			$this->posts[ $label ] = self::factory()->post->create(
				array(
					'post_title'    => 'HAP ' . $label,
					'post_author'   => $fixture[0],
					'post_category' => array( $fixture[1] ),
					'post_date'     => sprintf( '2026-01-%02d 12:00:00', $day ),
					'post_status'   => 'publish',
				)
			);
			++$day;
		}

		update_option( 'hap', array( 'categories' => array( $this->secret_cat ) ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Returns fixture post IDs for the given labels, sorted.
	 *
	 * @param string[] $labels Fixture labels.
	 * @return int[]
	 */
	private function ids( array $labels ) {
		$ids = array();
		foreach ( $labels as $label ) {
			$ids[] = $this->posts[ $label ];
		}
		sort( $ids );

		return $ids;
	}

	/**
	 * Returns fixture post IDs visible through WP_Query for the current user, sorted.
	 *
	 * @param array<string, mixed> $args Extra query args.
	 * @return int[]
	 */
	private function visible_ids( array $args = array() ) {
		$query = new WP_Query(
			array_merge(
				array(
					'post_type'      => 'post',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'post__in'       => array_values( $this->posts ),
				),
				$args
			)
		);

		$ids = array_map( 'intval', $query->posts );
		sort( $ids );

		return $ids;
	}

	/**
	 * Labels of posts visible to non-Administrators with the default fixture.
	 *
	 * @return string[]
	 */
	private function non_admin_labels() {
		return array( 'admin_public', 'editor_default', 'editor_public' );
	}

	/**
	 * Editors cannot see hidden-category or Administrator default-category posts.
	 *
	 * @return void
	 */
	public function test_editor_query_excludes_hidden_posts() {
		wp_set_current_user( $this->editor_id );

		$this->assertSame( $this->ids( $this->non_admin_labels() ), $this->visible_ids() );
	}

	/**
	 * Anonymous visitors get the same filtering as Editors.
	 *
	 * @return void
	 */
	public function test_anonymous_query_excludes_hidden_posts() {
		$this->assertSame( $this->ids( $this->non_admin_labels() ), $this->visible_ids() );
	}

	/**
	 * Administrators see everything.
	 *
	 * @return void
	 */
	public function test_admin_sees_all_posts() {
		wp_set_current_user( $this->admin_id );

		$this->assertSame( $this->ids( array_keys( $this->posts ) ), $this->visible_ids() );
	}

	/**
	 * Queries with suppress_filters (default get_posts()) are filtered too.
	 *
	 * @return void
	 */
	public function test_get_posts_with_suppress_filters_excludes_hidden_posts() {
		wp_set_current_user( $this->editor_id );

		$ids = array_map(
			'intval',
			get_posts(
				array(
					'numberposts' => -1,
					'fields'      => 'ids',
					'include'     => array_values( $this->posts ),
				)
			)
		);
		sort( $ids );

		$this->assertSame( $this->ids( $this->non_admin_labels() ), $ids );
	}

	/**
	 * Nothing is hidden when no categories are configured.
	 *
	 * @return void
	 */
	public function test_nothing_hidden_without_configured_categories() {
		update_option( 'hap', array( 'categories' => array() ) );
		wp_set_current_user( $this->editor_id );

		$this->assertSame( $this->ids( array_keys( $this->posts ) ), $this->visible_ids() );
	}

	/**
	 * The Administrator default-category rule can be disabled.
	 *
	 * @return void
	 */
	public function test_default_category_rule_can_be_disabled() {
		add_filter( 'hap_hide_admin_default_category_posts', '__return_false' );
		wp_set_current_user( $this->editor_id );

		$this->assertSame(
			$this->ids( array( 'admin_public', 'admin_default', 'editor_default', 'editor_public' ) ),
			$this->visible_ids()
		);
	}

	/**
	 * Selecting the default category hides all of its posts, whoever wrote them.
	 *
	 * @return void
	 */
	public function test_selecting_default_category_hides_all_default_posts() {
		update_option( 'hap', array( 'categories' => array( $this->secret_cat, $this->default_cat ) ) );
		wp_set_current_user( $this->editor_id );

		$this->assertSame( $this->ids( array( 'admin_public', 'editor_public' ) ), $this->visible_ids() );
	}

	/**
	 * Any user with manage_options counts as an Administrator for the default-category rule.
	 *
	 * @return void
	 */
	public function test_manage_options_role_counts_as_administrator() {
		add_role(
			'hap_site_manager',
			'Site Manager',
			array(
				'read'           => true,
				'manage_options' => true,
			)
		);

		$manager_id = self::factory()->user->create( array( 'role' => 'hap_site_manager' ) );
		$post_id    = self::factory()->post->create(
			array(
				'post_author'   => $manager_id,
				'post_category' => array( $this->default_cat ),
			)
		);

		wp_set_current_user( $this->editor_id );
		$visible = $this->visible_ids( array( 'post__in' => array( $post_id ) ) );

		remove_role( 'hap_site_manager' );

		$this->assertSame( array(), $visible );
	}

	/**
	 * Super Admins without a role on the site count as Administrators on Multisite.
	 *
	 * @return void
	 */
	public function test_super_admin_counts_as_administrator_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$super_id = self::factory()->user->create();
		remove_user_from_blog( $super_id, get_current_blog_id() );
		grant_super_admin( $super_id );

		$post_id = self::factory()->post->create(
			array(
				'post_author'   => $super_id,
				'post_category' => array( $this->default_cat ),
			)
		);

		wp_set_current_user( $this->editor_id );
		$visible = $this->visible_ids( array( 'post__in' => array( $post_id ) ) );

		revoke_super_admin( $super_id );

		$this->assertSame( array(), $visible );
	}

	/**
	 * Category changes are reflected immediately, even after a cached query.
	 *
	 * @return void
	 */
	public function test_category_change_invalidates_cached_results() {
		wp_set_current_user( $this->editor_id );
		$this->assertContains( $this->posts['editor_public'], $this->visible_ids() );

		wp_set_post_categories( $this->posts['editor_public'], array( $this->secret_cat ) );

		$this->assertNotContains( $this->posts['editor_public'], $this->visible_ids() );
	}

	/**
	 * Capability checks deny direct access by ID or WP_Post object.
	 *
	 * @return void
	 */
	public function test_capabilities_block_hidden_posts() {
		$hidden = $this->posts['admin_secret'];

		$this->assertFalse( user_can( $this->editor_id, 'edit_post', $hidden ) );
		$this->assertFalse( user_can( $this->editor_id, 'edit_post', get_post( $hidden ) ) );
		$this->assertFalse( user_can( $this->editor_id, 'read_post', $hidden ) );
		$this->assertFalse( user_can( $this->editor_id, 'delete_post', $hidden ) );
		$this->assertFalse( user_can( $this->editor_id, 'publish_post', $hidden ) );
		$this->assertFalse( user_can( $this->editor_id, 'edit_post', $this->posts['admin_default'] ) );

		$this->assertTrue( user_can( $this->editor_id, 'edit_post', $this->posts['admin_public'] ) );
		$this->assertTrue( user_can( $this->admin_id, 'edit_post', $hidden ) );
	}

	/**
	 * Single-post REST reads return 404 (not a fatal error) for hidden posts.
	 *
	 * @return void
	 */
	public function test_rest_single_post_returns_404_for_hidden_posts() {
		$hidden_path  = '/wp/v2/posts/' . $this->posts['admin_secret'];
		$visible_path = '/wp/v2/posts/' . $this->posts['admin_public'];

		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', $hidden_path ) )->get_status() );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', $visible_path ) )->get_status() );

		wp_set_current_user( $this->editor_id );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', $hidden_path ) )->get_status() );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', $hidden_path ) )->get_status() );
	}

	/**
	 * REST collections omit hidden posts.
	 *
	 * @return void
	 */
	public function test_rest_collection_excludes_hidden_posts() {
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'include', array_values( $this->posts ) );
		$request->set_param( 'per_page', 100 );

		$ids = array_map( 'intval', wp_list_pluck( rest_do_request( $request )->get_data(), 'id' ) );
		sort( $ids );

		$this->assertSame( $this->ids( $this->non_admin_labels() ), $ids );
	}

	/**
	 * An Editor's save that assigns a hidden category succeeds; later reads 404.
	 *
	 * @return void
	 */
	public function test_rest_save_into_hidden_category_succeeds() {
		wp_set_current_user( $this->editor_id );

		$path    = '/wp/v2/posts/' . $this->posts['editor_public'];
		$request = new WP_REST_Request( 'POST', $path );
		$request->set_param( 'categories', array( $this->secret_cat ) );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', $path ) )->get_status() );
	}

	/**
	 * Comments on hidden posts are excluded from queries, REST, and the comments feed,
	 * and new comments on hidden posts are refused.
	 *
	 * @return void
	 */
	public function test_comments_on_hidden_posts_are_hidden() {
		$hidden_comment  = self::factory()->comment->create( array( 'comment_post_ID' => $this->posts['admin_secret'] ) );
		$visible_comment = self::factory()->comment->create( array( 'comment_post_ID' => $this->posts['admin_public'] ) );

		$ids = array_map( 'intval', get_comments( array( 'fields' => 'ids' ) ) );
		$this->assertContains( $visible_comment, $ids );
		$this->assertNotContains( $hidden_comment, $ids );

		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/comments/' . $hidden_comment ) )->get_status() );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/comments/' . $visible_comment ) )->get_status() );

		$rest_ids = array_map( 'intval', wp_list_pluck( rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/comments' ) )->get_data(), 'id' ) );
		$this->assertContains( $visible_comment, $rest_ids );
		$this->assertNotContains( $hidden_comment, $rest_ids );

		$this->go_to( home_url( '/?feed=comments-rss2' ) );
		$feed_ids = array_map( 'intval', wp_list_pluck( (array) $GLOBALS['wp_query']->comments, 'comment_ID' ) );
		$this->assertContains( $visible_comment, $feed_ids );
		$this->assertNotContains( $hidden_comment, $feed_ids );

		$this->assertFalse( comments_open( $this->posts['admin_secret'] ) );
		$this->assertTrue( comments_open( $this->posts['admin_public'] ) );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( comments_open( $this->posts['admin_secret'] ) );
		$this->assertContains( $hidden_comment, array_map( 'intval', get_comments( array( 'fields' => 'ids' ) ) ) );
	}

	/**
	 * Previous/next post links skip hidden posts.
	 *
	 * @return void
	 */
	public function test_adjacent_posts_skip_hidden_posts() {
		$GLOBALS['post'] = get_post( $this->posts['editor_default'] );

		$previous = get_adjacent_post( false, '', true );

		$this->assertInstanceOf( WP_Post::class, $previous );
		$this->assertSame( $this->posts['admin_public'], (int) $previous->ID );
	}

	/**
	 * Archive listings omit hidden post titles.
	 *
	 * @return void
	 */
	public function test_archives_exclude_hidden_posts() {
		$archives = wp_get_archives(
			array(
				'type' => 'postbypost',
				'echo' => false,
			)
		);

		$this->assertStringContainsString( 'HAP admin_public', $archives );
		$this->assertStringNotContainsString( 'HAP admin_secret', $archives );
		$this->assertStringNotContainsString( 'HAP admin_default', $archives );
	}

	/**
	 * The oEmbed endpoint does not describe hidden posts.
	 *
	 * @return void
	 */
	public function test_oembed_excludes_hidden_posts() {
		$this->assertSame( 0, apply_filters( 'oembed_request_post_id', $this->posts['admin_secret'], '' ) );

		$request = new WP_REST_Request( 'GET', '/oembed/1.0/embed' );
		$request->set_param( 'url', add_query_arg( 'p', $this->posts['admin_secret'], home_url( '/' ) ) );

		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	/**
	 * Canonical redirects never reveal a hidden post's permalink.
	 *
	 * @return void
	 */
	public function test_canonical_redirect_to_hidden_post_is_cancelled() {
		$hidden_url  = get_permalink( $this->posts['admin_secret'] );
		$visible_url = get_permalink( $this->posts['admin_public'] );

		$this->assertFalse( apply_filters( 'redirect_canonical', $hidden_url, home_url( '/' ) ) );
		$this->assertSame( $visible_url, apply_filters( 'redirect_canonical', $visible_url, home_url( '/' ) ) );
	}

	/**
	 * Single-post front-end requests for hidden posts are 404s.
	 *
	 * @return void
	 */
	public function test_single_hidden_post_is_404() {
		$this->go_to( get_permalink( $this->posts['admin_secret'] ) );

		$this->assertTrue( is_404() );
	}

	/**
	 * REST routes match case-insensitively in core, so the guard must too.
	 *
	 * @return void
	 */
	public function test_rest_mixed_case_routes_return_404_for_hidden_items() {
		$hidden_comment = self::factory()->comment->create( array( 'comment_post_ID' => $this->posts['admin_secret'] ) );

		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/Posts/' . $this->posts['admin_secret'] ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/WP/V2/POSTS/' . $this->posts['admin_secret'] ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/Comments/' . $hidden_comment ) )->get_status() );
	}

	/**
	 * A query-string `id` overrides the route ID in WP_REST_Request, so it is checked too.
	 *
	 * @return void
	 */
	public function test_rest_id_parameter_cannot_override_route_id() {
		$hidden_comment  = self::factory()->comment->create( array( 'comment_post_ID' => $this->posts['admin_secret'] ) );
		$visible_comment = self::factory()->comment->create( array( 'comment_post_ID' => $this->posts['admin_public'] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->posts['admin_public'] );
		$request->set_query_params( array( 'id' => $this->posts['admin_secret'] ) );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );

		$request = new WP_REST_Request( 'GET', '/wp/v2/comments/' . $visible_comment );
		$request->set_query_params( array( 'id' => $hidden_comment ) );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	/**
	 * A `/0` route segment, a JSON body `id`, or a non-canonical `id` cannot skip the guard.
	 *
	 * @return void
	 */
	public function test_rest_zero_route_and_noncanonical_ids_are_checked() {
		$hidden         = $this->posts['admin_secret'];
		$hidden_comment = self::factory()->comment->create( array( 'comment_post_ID' => $hidden ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/0' );
		$request->set_query_params( array( 'id' => $hidden ) );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );

		$request = new WP_REST_Request( 'GET', '/wp/v2/comments/0' );
		$request->set_query_params( array( 'id' => $hidden_comment ) );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/0' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'id' => $hidden ) ) );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );

		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->posts['editor_public'] . '/autosaves' );
		$request->set_query_params( array( 'id' => '+' . $hidden ) );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	/**
	 * A save that assigns a hidden category can still write its post meta.
	 *
	 * @return void
	 */
	public function test_rest_save_into_hidden_category_with_meta_succeeds() {
		register_post_meta(
			'post',
			'hap_test_note',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			)
		);

		wp_set_current_user( $this->editor_id );

		$post_id = $this->posts['editor_public'];
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'categories', array( $this->secret_cat ) );
		$request->set_param( 'meta', array( 'hap_test_note' => 'saved' ) );

		$status = rest_do_request( $request )->get_status();

		unregister_post_meta( 'post', 'hap_test_note' );

		$this->assertSame( 200, $status );
		$this->assertSame( 'saved', get_post_meta( $post_id, 'hap_test_note', true ) );
		$this->assertFalse( user_can( $this->editor_id, 'edit_post', $post_id ) );
	}

	/**
	 * Only Administrators can delete a hidden category.
	 *
	 * @return void
	 */
	public function test_editors_cannot_delete_hidden_categories() {
		$this->assertFalse( user_can( $this->editor_id, 'delete_term', $this->secret_cat ) );
		$this->assertTrue( user_can( $this->editor_id, 'delete_term', $this->public_cat ) );
		$this->assertTrue( user_can( $this->admin_id, 'delete_term', $this->secret_cat ) );
	}

	/**
	 * Creating a subsite does not run Hide A Post option queries against missing tables.
	 *
	 * @return void
	 */
	public function test_site_creation_causes_no_database_errors() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		global $EZSQL_ERROR;

		$before = is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;

		$site_id = wp_insert_site(
			array(
				'domain' => 'example.org',
				'path'   => '/hap-' . wp_generate_password( 8, false ) . '/',
			)
		);

		$errors = array_slice( is_array( $EZSQL_ERROR ) ? $EZSQL_ERROR : array(), $before );
		$hap    = array_filter(
			$errors,
			static function ( $error ) {
				return false !== strpos( (string) $error['query'], "'hap" );
			}
		);

		$this->assertIsInt( $site_id );
		$this->assertSame( array(), array_values( $hap ) );
	}

	/**
	 * A visible post's own comment feed still works (no posts-table join there).
	 *
	 * @return void
	 */
	public function test_singular_comment_feed_for_visible_post_works() {
		global $wpdb;

		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $this->posts['admin_public'] ) );

		$this->go_to( add_query_arg( 'feed', 'rss2', get_permalink( $this->posts['admin_public'] ) ) );

		$this->assertTrue( is_comment_feed() );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertContains( $comment, array_map( 'intval', wp_list_pluck( (array) $GLOBALS['wp_query']->comments, 'comment_ID' ) ) );
	}

	/**
	 * Non-post canonical redirects are kept even when the global post is hidden.
	 *
	 * @return void
	 */
	public function test_canonical_redirect_ignores_global_post_for_non_post_urls() {
		$GLOBALS['post'] = get_post( $this->posts['admin_secret'] );

		$url = home_url( '/' );

		$this->assertSame( $url, apply_filters( 'redirect_canonical', $url, home_url( '/?foo=bar' ) ) );
	}

	/**
	 * Revisions of hidden posts are blocked like their parent.
	 *
	 * @return void
	 */
	public function test_capabilities_block_revisions_of_hidden_posts() {
		$revision = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $this->posts['admin_secret'],
				'post_name'   => $this->posts['admin_secret'] . '-revision-v1',
			)
		);

		$this->assertFalse( user_can( $this->editor_id, 'read_post', $revision ) );
		$this->assertFalse( user_can( $this->editor_id, 'edit_post', $revision ) );
		$this->assertTrue( user_can( $this->admin_id, 'read_post', $revision ) );
	}

	/**
	 * Old-slug redirects do not reveal a renamed hidden post.
	 *
	 * @return void
	 */
	public function test_old_slug_redirect_excludes_hidden_posts() {
		$this->assertSame( 0, apply_filters( 'old_slug_redirect_post_id', $this->posts['admin_secret'] ) );
		$this->assertSame( $this->posts['admin_public'], apply_filters( 'old_slug_redirect_post_id', $this->posts['admin_public'] ) );
	}

	/**
	 * Queries that set suppress_filters late in pre_get_posts are still filtered.
	 *
	 * @return void
	 */
	public function test_late_suppress_filters_is_still_filtered() {
		$late = static function ( $query ) {
			$query->set( 'suppress_filters', true );
		};
		add_action( 'pre_get_posts', $late, 999 );

		$visible = $this->visible_ids();

		remove_action( 'pre_get_posts', $late, 999 );

		$this->assertSame( $this->ids( $this->non_admin_labels() ), $visible );
	}

	/**
	 * Legacy settings on a subsite are migrated when another site switches to it.
	 *
	 * @return void
	 */
	public function test_switch_to_blog_migrates_legacy_settings() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$category = self::factory()->category->create( array( 'name' => 'Legacy Secret' ) );
		update_option( 'hap', array( 'categories' => 'Legacy Secret' ) );
		delete_option( HAP_Plugin::DB_VERSION_OPTION );
		restore_current_blog();

		switch_to_blog( $blog_id );
		$hidden = HAP_Plugin::get_hidden_category_ids();
		restore_current_blog();

		$this->assertSame( array( $category ), $hidden );
	}
}
