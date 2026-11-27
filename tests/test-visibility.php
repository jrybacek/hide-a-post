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
	 * Creating a post into a hidden category with meta succeeds, even when a
	 * nested REST request runs during the save.
	 *
	 * @return void
	 */
	public function test_rest_create_into_hidden_category_with_meta_and_nested_request() {
		register_post_meta(
			'post',
			'hap_test_note',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			)
		);
		$nested = static function () {
			rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/categories' ) );
		};
		add_action( 'rest_insert_post', $nested, 5 );

		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_param( 'title', 'HAP created' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'categories', array( $this->secret_cat ) );
		$request->set_param( 'meta', array( 'hap_test_note' => 'created' ) );
		$created = rest_do_request( $request );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->posts['editor_public'] );
		$request->set_param( 'categories', array( $this->secret_cat ) );
		$request->set_param( 'meta', array( 'hap_test_note' => 'updated' ) );
		$updated = rest_do_request( $request );

		remove_action( 'rest_insert_post', $nested, 5 );
		unregister_post_meta( 'post', 'hap_test_note' );

		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'created', get_post_meta( (int) $created->get_data()['id'], 'hap_test_note', true ) );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'updated', get_post_meta( $this->posts['editor_public'], 'hap_test_note', true ) );
		$this->assertFalse( user_can( $this->editor_id, 'edit_post', (int) $created->get_data()['id'] ) );
	}

	/**
	 * Core REST endpoints that take a `post` parameter cannot target a hidden post.
	 *
	 * @return void
	 */
	public function test_rest_post_parameter_cannot_target_hidden_posts() {
		$hidden = $this->posts['admin_secret'];

		$request = new WP_REST_Request( 'GET', '/wp/v2/categories' );
		$request->set_query_params( array( 'post' => $hidden ) );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );

		$request = new WP_REST_Request( 'GET', '/wp/v2/categories' );
		$request->set_query_params( array( 'post' => $this->posts['admin_public'] ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		wp_set_current_user( $this->editor_id );
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->posts['editor_public'],
				'user_id'         => $this->editor_id,
			)
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments/' . $comment );
		$request->set_param( 'post', $hidden );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );
		$this->assertSame( (string) $this->posts['editor_public'], get_comment( $comment )->comment_post_ID );
	}

	/**
	 * Hidden categories cannot be deleted by non-Administrators through any path,
	 * including core's bulk delete (which only checks `delete_terms`).
	 *
	 * @return void
	 */
	public function test_hidden_category_deletion_is_blocked_for_editors() {
		wp_set_current_user( $this->editor_id );

		try {
			wp_delete_term( $this->secret_cat, 'category' );
			$this->fail( 'Expected wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertInstanceOf( WP_Term::class, get_term( $this->secret_cat, 'category' ) );
		}

		$this->assertSame( array( $this->secret_cat ), HAP_Plugin::get_hidden_category_ids() );
		$this->assertTrue( wp_delete_term( $this->public_cat, 'category' ) );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( wp_delete_term( $this->secret_cat, 'category' ) );
	}

	/**
	 * Hidden post titles read by ID are replaced for filtered users.
	 *
	 * @return void
	 */
	public function test_hidden_post_titles_are_replaced() {
		wp_set_current_user( $this->editor_id );

		$this->assertSame( '(Private post)', get_the_title( $this->posts['admin_secret'] ) );
		$this->assertSame( 'HAP admin_public', get_the_title( $this->posts['admin_public'] ) );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( 'HAP admin_secret', get_the_title( $this->posts['admin_secret'] ) );
	}

	/**
	 * The post-scoped Comments screen is blocked for hidden posts.
	 *
	 * @return void
	 */
	public function test_post_comments_screen_is_blocked_for_hidden_posts() {
		wp_set_current_user( $this->editor_id );

		$_REQUEST['p'] = (string) $this->posts['editor_public'];
		do_action( 'load-edit-comments.php' );

		// Core parses `p` with absint(), so `-<id>` targets the same post.
		foreach ( array( (string) $this->posts['admin_secret'], '-' . $this->posts['admin_secret'] ) as $value ) {
			$_REQUEST['p'] = $value;
			try {
				do_action( 'load-edit-comments.php' );
				$this->fail( 'Expected wp_die() for p=' . $value );
			} catch ( WPDieException $e ) {
				$this->assertStringContainsString( 'not allowed', $e->getMessage() );
			} finally {
				unset( $_REQUEST['p'] );
			}
		}
	}

	/**
	 * Attachments of hidden posts do not expose the parent's permalink.
	 *
	 * @return void
	 */
	public function test_attachment_links_hide_hidden_parent_permalink() {
		$this->set_permalink_structure( '/%postname%/' );

		$attachment  = self::factory()->attachment->create(
			array(
				'post_parent'    => $this->posts['admin_secret'],
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'hap-photo',
			)
		);
		$hidden_slug = get_post_field( 'post_name', $this->posts['admin_secret'] );

		wp_set_current_user( $this->editor_id );
		$link = get_permalink( $attachment );
		$this->assertStringNotContainsString( $hidden_slug, $link );
		$this->assertStringContainsString( 'attachment_id=' . $attachment, $link );

		wp_set_current_user( $this->admin_id );
		$this->assertStringContainsString( $hidden_slug, get_permalink( $attachment ) );
	}

	/**
	 * Classic menu items and Navigation Link blocks for hidden posts are not rendered.
	 *
	 * @return void
	 */
	public function test_menus_skip_hidden_posts() {
		$menu_id = $this->create_menu_with_hidden_and_visible_items();

		// Stored menu data is untouched; only wp_nav_menu() output is filtered.
		$this->assertCount( 2, wp_get_nav_menu_items( $menu_id ) );

		$html = (string) wp_nav_menu(
			array(
				'menu'     => $menu_id,
				'echo'     => false,
				'fallback' => false,
			)
		);
		$this->assertStringContainsString( get_permalink( $this->posts['admin_public'] ), $html );
		$this->assertStringNotContainsString( 'admin_secret', $html );

		foreach ( array( array( 'kind' => 'post-type' ), array( 'type' => 'post' ) ) as $attrs ) {
			$block = array(
				'blockName' => 'core/navigation-link',
				'attrs'     => $attrs + array( 'id' => $this->posts['admin_secret'] ),
			);
			$this->assertSame( '', apply_filters( 'render_block_core/navigation-link', '<li>link</li>', $block ) );

			$block['attrs']['id'] = $this->posts['admin_public'];
			$this->assertSame( '<li>link</li>', apply_filters( 'render_block_core/navigation-link', '<li>link</li>', $block ) );
		}
	}

	/**
	 * REST menu items (readable with `edit_posts`) are redacted for hidden posts.
	 *
	 * @return void
	 */
	public function test_rest_menu_items_redact_hidden_posts() {
		$menu_id = $this->create_menu_with_hidden_and_visible_items();

		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/menu-items' );
		$request->set_query_params( array( 'menus' => $menu_id ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$by_object = array();
		foreach ( $response->get_data() as $item ) {
			$by_object[ (int) $item['object_id'] ] = $item;
		}

		$this->assertSame( '', $by_object[ $this->posts['admin_secret'] ]['url'] );
		$this->assertSame( '(Private post)', $by_object[ $this->posts['admin_secret'] ]['title']['rendered'] );
		$this->assertArrayNotHasKey( 'raw', $by_object[ $this->posts['admin_secret'] ]['title'] );
		$this->assertSame( get_permalink( $this->posts['admin_public'] ), $by_object[ $this->posts['admin_public'] ]['url'] );
	}

	/**
	 * Navigation post content served over REST omits links to hidden posts.
	 *
	 * @return void
	 */
	public function test_rest_navigation_content_strips_hidden_links() {
		$hidden_url = get_permalink( $this->posts['admin_secret'] );
		$content    = sprintf(
			'<!-- wp:navigation-link {"label":"Secret","type":"post","kind":"post-type","id":%1$d,"url":"%2$s"} /--><!-- wp:navigation-link {"label":"Public","type":"post","kind":"post-type","id":%3$d,"url":"%4$s"} /-->',
			$this->posts['admin_secret'],
			$hidden_url,
			$this->posts['admin_public'],
			get_permalink( $this->posts['admin_public'] )
		);
		$nav_id     = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/navigation/' . $nav_id );
		$request->set_query_params( array( 'context' => 'embed' ) );
		$raw = (string) rest_do_request( $request )->get_data()['content']['raw'];

		$this->assertStringNotContainsString( $hidden_url, $raw );
		$this->assertStringContainsString( '"Public"', $raw );
		$this->assertSame( $content, get_post_field( 'post_content', $nav_id ) );
	}

	/**
	 * Builds Navigation markup with a hidden-post submenu (with description) that
	 * wraps a visible and a hidden link, plus a visible submenu wrapping a hidden link.
	 *
	 * @return string
	 */
	private function nested_navigation_markup() {
		$link = static function ( $label, $id ) {
			return sprintf(
				'<!-- wp:navigation-link {"label":"%1$s","type":"post","kind":"post-type","id":%2$d,"url":"%3$s"} /-->',
				$label,
				$id,
				get_permalink( $id )
			);
		};

		return sprintf(
			'<!-- wp:navigation-submenu {"label":"SecretParent","type":"post","kind":"post-type","id":%1$d,"url":"%2$s","description":"SecretDescription"} -->%3$s%4$s<!-- /wp:navigation-submenu --><!-- wp:navigation-submenu {"label":"PublicParent","type":"post","kind":"post-type","id":%5$d,"url":"%6$s"} -->%7$s%8$s<!-- /wp:navigation-submenu -->',
			$this->posts['admin_secret'],
			get_permalink( $this->posts['admin_secret'] ),
			$link( 'PublicChild', $this->posts['admin_public'] ),
			$link( 'SecretChild', $this->posts['admin_secret'] ),
			$this->posts['editor_public'],
			get_permalink( $this->posts['editor_public'] ),
			$link( 'SecretChildTwo', $this->posts['admin_secret'] ),
			$link( 'PublicChildTwo', $this->posts['editor_default'] )
		);
	}

	/**
	 * Nested hidden links are removed from Navigation REST content without
	 * corrupting the serialized markup, and hidden submenus lose their description.
	 *
	 * @return void
	 */
	public function test_rest_navigation_strips_nested_hidden_links() {
		$nav_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_content' => $this->nested_navigation_markup(),
			)
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/navigation/' . $nav_id );
		$request->set_query_params( array( 'context' => 'embed' ) );
		$raw = (string) rest_do_request( $request )->get_data()['content']['raw'];

		foreach ( array( 'SecretParent', 'SecretDescription', 'SecretChild', '"url":"' . get_permalink( $this->posts['admin_secret'] ) . '"' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $raw );
		}
		foreach ( array( 'PublicChild', 'PublicParent', 'PublicChildTwo', '(Private post)' ) as $needle ) {
			$this->assertStringContainsString( $needle, $raw );
		}

		$blocks = parse_blocks( $raw );
		$this->assertCount( 2, $blocks );
		$this->assertCount( 1, $blocks[0]['innerBlocks'] );
		$this->assertCount( 1, $blocks[1]['innerBlocks'] );
	}

	/**
	 * Hidden-post submenus render without the post's label or URL, keeping visible children.
	 *
	 * @return void
	 */
	public function test_navigation_submenu_renders_redacted_for_visitors() {
		$html = do_blocks( $this->nested_navigation_markup() );

		$this->assertStringNotContainsString( 'SecretParent', $html );
		$this->assertStringNotContainsString( 'SecretChild', $html );
		$this->assertStringNotContainsString( get_permalink( $this->posts['admin_secret'] ), $html );
		$this->assertStringContainsString( get_permalink( $this->posts['admin_public'] ), $html );
		$this->assertStringContainsString( 'PublicParent', $html );

		wp_set_current_user( $this->admin_id );
		$this->assertStringContainsString( 'SecretParent', do_blocks( $this->nested_navigation_markup() ) );

		// An unpublished hidden target drops the whole submenu on every supported
		// version (as WP 7.1+ core does), never reviving it as "(Private post)".
		wp_set_current_user( 0 );
		wp_update_post(
			array(
				'ID'          => $this->posts['admin_secret'],
				'post_status' => 'draft',
			)
		);
		$html = do_blocks( $this->nested_navigation_markup() );
		$this->assertStringNotContainsString( 'SecretParent', $html );
		$this->assertStringNotContainsString( 'SecretDescription', $html );
		$this->assertStringNotContainsString( '(Private post)', $html );
		$this->assertStringNotContainsString( 'PublicChild<', $html );
		$this->assertStringContainsString( 'PublicParent', $html );
	}

	/**
	 * Hidden submenus inside an inline Navigation block or a Group are redacted
	 * (their attributes are not left cached unredacted), and label-less hidden
	 * submenus stay suppressed as core does.
	 *
	 * @return void
	 */
	public function test_wrapped_hidden_submenus_are_redacted() {
		$submenu = sprintf(
			'<!-- wp:navigation-submenu {"label":"SecretParent","type":"post","kind":"post-type","id":%1$d,"url":"%2$s","description":"SecretDescription"} --><!-- wp:navigation-link {"label":"PublicChild","type":"post","kind":"post-type","id":%3$d,"url":"%4$s"} /--><!-- /wp:navigation-submenu -->',
			$this->posts['admin_secret'],
			get_permalink( $this->posts['admin_secret'] ),
			$this->posts['admin_public'],
			get_permalink( $this->posts['admin_public'] )
		);

		$wrappers = array(
			'inline navigation' => '<!-- wp:navigation -->' . $submenu . '<!-- /wp:navigation -->',
			'group'             => '<!-- wp:group --><div class="wp-block-group">' . $submenu . '</div><!-- /wp:group -->',
		);

		foreach ( $wrappers as $label => $markup ) {
			$html = do_blocks( $markup );
			$this->assertStringNotContainsString( 'SecretParent', $html, $label );
			$this->assertStringNotContainsString( 'SecretDescription', $html, $label );
			$this->assertStringNotContainsString( get_permalink( $this->posts['admin_secret'] ), $html, $label );
			$this->assertStringContainsString( 'PublicChild', $html, $label );
		}

		// Labels core treats as empty stay suppressed.
		foreach ( array( '""', '"0"' ) as $empty_label ) {
			$label_less = str_replace( '"label":"SecretParent",', '"label":' . $empty_label . ',', $submenu );
			$html       = do_blocks( $label_less );
			$this->assertStringNotContainsString( '(Private post)', $html, $empty_label );
			$this->assertStringNotContainsString( 'PublicChild', $html, $empty_label );
		}
	}

	/**
	 * A Navigation block whose `ref` is a hidden post renders nothing, including
	 * through the REST block renderer.
	 *
	 * @return void
	 */
	public function test_navigation_ref_to_hidden_post_renders_nothing() {
		wp_update_post(
			array(
				'ID'           => $this->posts['admin_secret'],
				'post_content' => '<!-- wp:paragraph --><p>SecretBody</p><!-- /wp:paragraph -->',
			)
		);

		foreach ( array( 'ref', 'navigationMenuId' ) as $attribute ) {
			$markup = '<!-- wp:navigation {"' . $attribute . '":' . $this->posts['admin_secret'] . '} /-->';
			$this->assertStringNotContainsString( 'SecretBody', do_blocks( $markup ), $attribute );
		}

		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'POST', '/wp/v2/block-renderer/core/navigation' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'attributes', array( 'ref' => $this->posts['admin_secret'] ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( 'SecretBody', (string) $response->get_data()['rendered'] );
		$this->assertStringNotContainsString( 'HAP admin_secret', (string) $response->get_data()['rendered'] );
	}

	/**
	 * Redacting nested hidden submenus adds no rendering work, inside a Navigation
	 * block or when submenus are rendered standalone.
	 *
	 * Core itself renders a submenu's children more than once per level, so the
	 * baseline is the same structure pointing at a visible post.
	 *
	 * @return void
	 */
	public function test_nested_hidden_submenus_do_not_multiply_rendering() {
		$build = function ( $submenu_target ) {
			$markup = sprintf(
				'<!-- wp:navigation-link {"label":"Leaf","type":"post","kind":"post-type","id":%1$d,"url":"%2$s"} /-->',
				$this->posts['admin_public'],
				get_permalink( $this->posts['admin_public'] )
			);
			for ( $depth = 0; $depth < 5; $depth++ ) {
				$markup = sprintf(
					'<!-- wp:navigation-submenu {"label":"Level%1$d","type":"post","kind":"post-type","id":%2$d} -->%3$s<!-- /wp:navigation-submenu -->',
					$depth,
					$submenu_target,
					$markup
				);
			}
			return $markup;
		};

		$renders = 0;
		$counter = static function ( $content, $block ) use ( &$renders ) {
			if ( isset( $block['attrs']['label'] ) && 'Leaf' === $block['attrs']['label'] ) {
				++$renders;
			}
			return $content;
		};
		$count   = function ( $markup, $in_navigation ) use ( &$renders ) {
			$renders = 0;
			if ( $in_navigation ) {
				$nav_id = self::factory()->post->create(
					array(
						'post_type'    => 'wp_navigation',
						'post_status'  => 'publish',
						'post_content' => $markup,
					)
				);
				$markup = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';
			}
			$html = do_blocks( $markup );
			return array( $renders, $html );
		};

		add_filter( 'render_block_core/navigation-link', $counter, 5, 2 );

		list( $baseline_inside ) = $count( $build( $this->posts['admin_public'] ), true );
		list( $baseline_alone )  = $count( $build( $this->posts['admin_public'] ), false );
		list( $inside, $html )   = $count( $build( $this->posts['admin_secret'] ), true );
		list( $alone )           = $count( $build( $this->posts['admin_secret'] ), false );

		remove_filter( 'render_block_core/navigation-link', $counter, 5 );

		$this->assertStringContainsString( 'Leaf', $html );
		$this->assertStringNotContainsString( 'Level0', $html );
		$this->assertSame( $baseline_inside, $inside );
		$this->assertSame( $baseline_alone, $alone );
	}

	/**
	 * A visitor's page load never converts the classic menu into a saved (filtered)
	 * Navigation post; an Administrator's does, with complete data.
	 *
	 * @return void
	 */
	public function test_navigation_fallback_is_not_created_from_filtered_view() {
		$this->create_menu_with_hidden_and_visible_items();

		$this->assertFalse( apply_filters( 'wp_navigation_should_create_fallback', true ) );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( apply_filters( 'wp_navigation_should_create_fallback', true ) );

		$fallback = WP_Navigation_Fallback::get_fallback();
		$this->assertInstanceOf( WP_Post::class, $fallback );
		$this->assertStringContainsString( (string) $this->posts['admin_secret'], $fallback->post_content );
		$this->assertStringNotContainsString( '(Private post)', $fallback->post_content );
	}

	/**
	 * Creates a classic menu with one hidden and one visible post item.
	 *
	 * @return int Menu ID.
	 */
	private function create_menu_with_hidden_and_visible_items() {
		$menu_id = (int) wp_create_nav_menu( 'HAP menu ' . wp_generate_password( 6, false ) );
		foreach ( array( 'admin_secret', 'admin_public' ) as $label ) {
			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-type'      => 'post_type',
					'menu-item-object'    => 'post',
					'menu-item-object-id' => $this->posts[ $label ],
					'menu-item-status'    => 'publish',
				)
			);
		}

		return $menu_id;
	}

	/**
	 * The Empty Trash rewrite only applies to the Trash view.
	 *
	 * @return void
	 */
	public function test_empty_trash_rewrite_ignores_non_trash_statuses() {
		wp_set_current_user( $this->editor_id );

		foreach ( array( 'all', 'any', 'publish' ) as $status ) {
			$_REQUEST['delete_all']  = 'Empty Trash';
			$_REQUEST['post_status'] = $status;

			do_action( 'load-edit.php' );

			$rewritten = isset( $_REQUEST['post'] ) || ! isset( $_REQUEST['delete_all'] );
			unset( $_REQUEST['delete_all'], $_REQUEST['post_status'], $_REQUEST['post'], $_REQUEST['action'] );

			$this->assertFalse( $rewritten, 'post_status=' . $status );
		}
	}

	/**
	 * "Empty Trash" deletes only the trashed posts the Editor can see.
	 *
	 * @return void
	 */
	public function test_empty_trash_is_limited_to_visible_posts() {
		wp_trash_post( $this->posts['admin_secret'] );
		wp_trash_post( $this->posts['editor_public'] );

		wp_set_current_user( $this->editor_id );

		$_REQUEST['delete_all']  = 'Empty Trash';
		$_REQUEST['post_status'] = 'trash';

		do_action( 'load-edit.php' );

		$post = $_REQUEST['post'];
		$kept = isset( $_REQUEST['delete_all'] );
		unset( $_REQUEST['delete_all'], $_REQUEST['post_status'], $_REQUEST['post'], $_REQUEST['action'] );

		$this->assertFalse( $kept );
		$this->assertSame( array( (string) $this->posts['editor_public'] ), $post );
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
