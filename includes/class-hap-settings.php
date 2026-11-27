<?php
/**
 * Admin settings screen for Hide A Post.
 *
 * @package Hide_A_Post
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Hide A Post settings screen with category checkboxes.
 *
 * @since 2.0.0
 */
final class HAP_Settings {

	/**
	 * Settings group name.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const OPTION_GROUP = 'hap_options';

	/**
	 * Settings section identifier.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const SECTION_ID = 'hap_general_section';

	/**
	 * Registers admin menu and Settings API hooks.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Adds the Hide A Post page under the Settings menu.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'Hide A Post', 'hide-a-post' ),
			__( 'Hide A Post', 'hide-a-post' ),
			'manage_options',
			HAP_SETTINGS_PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the plugin setting, section, and category checkboxes field.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			HAP_OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => array( 'categories' => array() ),
			)
		);

		add_settings_section(
			self::SECTION_ID,
			__( 'Hidden Categories', 'hide-a-post' ),
			array( $this, 'render_section_description' ),
			HAP_SETTINGS_PAGE
		);

		add_settings_field(
			'hap_categories',
			__( 'Categories to hide from non-Administrators', 'hide-a-post' ),
			array( $this, 'render_categories_field' ),
			HAP_SETTINGS_PAGE,
			self::SECTION_ID
		);
	}

	/**
	 * Renders the settings section intro text.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Select the categories that should be hidden on the site, in search results, and in WordPress admin for non-Administrator users.', 'hide-a-post' ) . '</p>';
	}

	/**
	 * Renders the settings page markup.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( HAP_SETTINGS_PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the category checkboxes field.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_categories_field() {
		$selected_ids = HAP_Plugin::get_hidden_category_ids();
		$categories   = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		// Ensures an array payload is submitted even when all checkboxes are unchecked.
		echo '<input type="hidden" name="' . esc_attr( HAP_OPTION_NAME ) . '[submitted]" value="1" />';

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			echo '<p class="description">' . esc_html__( 'No categories found. Create a category first to configure Hide A Post.', 'hide-a-post' ) . '</p>';
			return;
		}

		$ordered_categories = $this->order_categories_hierarchically( $categories );
		$default_cat_id     = absint( get_option( 'default_category', 0 ) );
		$existing_ids       = array();

		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Categories to hide from non-Administrators', 'hide-a-post' ) . '</span></legend>';

		foreach ( $ordered_categories as $item ) {
			/**
			 * Category term object.
			 *
			 * @var WP_Term $category
			 */
			$category       = $item['term'];
			$depth          = (int) $item['depth'];
			$ancestor_path  = (string) $item['ancestors'];
			$term_id        = (int) $category->term_id;
			$checked        = in_array( $term_id, $selected_ids, true );
			$input_id       = 'hap-category-' . $term_id;
			$existing_ids[] = $term_id;

			echo '<label for="' . esc_attr( $input_id ) . '" style="display:block;margin-bottom:6px;">';
			echo '<input type="checkbox" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( HAP_OPTION_NAME ) . '[categories][]" value="' . esc_attr( (string) $term_id ) . '" ' . checked( $checked, true, false ) . ' /> ';

			if ( $depth > 0 ) {
				// Visual indent for sighted users; full ancestor path for screen readers.
				echo '<span aria-hidden="true">' . esc_html( str_repeat( "\u{2014} ", $depth ) ) . '</span>';
				echo '<span class="screen-reader-text">' . esc_html( $ancestor_path . ' > ' ) . '</span>';
			}

			echo esc_html( $category->name );
			echo ' <span class="description">(' . esc_html(
				sprintf(
					/* translators: %s: Number of posts in the category. */
					_n( '%s post', '%s posts', (int) $category->count, 'hide-a-post' ),
					number_format_i18n( (int) $category->count )
				)
			) . ')</span>';

			if ( $term_id === $default_cat_id ) {
				echo ' <span class="description">&ndash; ' . esc_html__( 'default category', 'hide-a-post' ) . '</span>';
			}

			echo '</label>';
		}

		echo '</fieldset>';

		$orphaned_ids = array_diff( $selected_ids, $existing_ids );
		if ( ! empty( $orphaned_ids ) ) {
			echo '<p class="description"><strong>' . esc_html(
				sprintf(
					/* translators: %d: Number of saved categories that no longer exist. */
					_n(
						'%d previously selected category no longer exists and will be removed when you save.',
						'%d previously selected categories no longer exist and will be removed when you save.',
						count( $orphaned_ids ),
						'hide-a-post'
					),
					count( $orphaned_ids )
				)
			) . '</strong></p>';
		}

		echo '<p class="description">' . esc_html__( 'Selecting a parent category does not hide posts that are only assigned to its child categories; select each category you want hidden.', 'hide-a-post' ) . '</p>';

		$default_category = $default_cat_id > 0 ? get_term( $default_cat_id, 'category' ) : null;
		if ( $default_category instanceof WP_Term ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: Default category name. */
					__( 'When at least one category is selected, posts written by Administrators that are in the default category (%s) are also hidden from non-Administrators.', 'hide-a-post' ),
					$default_category->name
				)
			) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'Non-Administrators who assign a hidden category to a post immediately lose access to that post.', 'hide-a-post' ) . '</p>';
	}

	/**
	 * Sanitizes and validates the saved plugin options.
	 *
	 * Supports checkbox arrays of term IDs as well as legacy comma-separated
	 * category name strings for backward compatibility.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $input Raw option payload from the settings form or API.
	 * @return array{categories: int[]}
	 */
	public function sanitize_options( $input ) {
		if ( ! is_array( $input ) || ! isset( $input['categories'] ) ) {
			return array( 'categories' => array() );
		}

		$invalid_items = array();
		$valid_ids     = HAP_Plugin::resolve_category_ids( $input['categories'], $invalid_items );

		if ( ! is_string( $input['categories'] ) && ! is_array( $input['categories'] ) ) {
			$invalid_items[] = is_scalar( $input['categories'] ) ? (string) $input['categories'] : '';
		}

		// add_settings_error() is only loaded in wp-admin; programmatic updates elsewhere skip the notice.
		if ( ! empty( $invalid_items ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				HAP_OPTION_NAME,
				'hap_invalid_categories',
				__( 'One or more selected categories could not be found and were ignored.', 'hide-a-post' ),
				'warning'
			);
		}

		return array(
			'categories' => $valid_ids,
		);
	}

	/**
	 * Orders categories hierarchically (parent followed by children) with depth and full-path metadata.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Term[] $categories Flat list of category terms.
	 * @return array<int, array{term: WP_Term, depth: int, ancestors: string}>
	 */
	private function order_categories_hierarchically( array $categories ) {
		$by_parent = array();
		$by_id     = array();

		foreach ( $categories as $category ) {
			if ( ! ( $category instanceof WP_Term ) ) {
				continue;
			}
			$by_id[ (int) $category->term_id ] = $category;
		}

		foreach ( $by_id as $category ) {
			$parent_id                 = isset( $by_id[ (int) $category->parent ] ) ? (int) $category->parent : 0;
			$by_parent[ $parent_id ][] = $category;
		}

		$ordered = array();
		$visited = array();

		$this->collect_children( 0, 0, '', $by_parent, $ordered, $visited );

		// Append any remaining terms (e.g. cyclic parent edge case) at root depth.
		foreach ( $by_id as $term_id => $category ) {
			if ( ! isset( $visited[ $term_id ] ) ) {
				$ordered[] = array(
					'term'      => $category,
					'depth'     => 0,
					'ancestors' => '',
				);
			}
		}

		return $ordered;
	}

	/**
	 * Recursively collects category terms in parent-first order.
	 *
	 * @since 2.0.0
	 *
	 * @param int                                                             $parent_id   Parent term ID.
	 * @param int                                                             $depth       Current nesting depth.
	 * @param string                                                          $parent_path Ancestor path label.
	 * @param array<int, WP_Term[]>                                           $by_parent   Terms grouped by parent ID.
	 * @param array<int, array{term: WP_Term, depth: int, ancestors: string}> $ordered     Output list passed by reference.
	 * @param array<int, bool>                                                $visited     Visited term IDs map passed by reference.
	 * @return void
	 */
	private function collect_children( $parent_id, $depth, $parent_path, array $by_parent, array &$ordered, array &$visited ) {
		if ( empty( $by_parent[ $parent_id ] ) ) {
			return;
		}

		foreach ( $by_parent[ $parent_id ] as $category ) {
			$term_id = (int) $category->term_id;
			if ( isset( $visited[ $term_id ] ) ) {
				continue;
			}

			$path = '' !== $parent_path ? $parent_path . ' > ' . $category->name : $category->name;

			$visited[ $term_id ] = true;
			$ordered[]           = array(
				'term'      => $category,
				'depth'     => $depth,
				'ancestors' => $parent_path,
			);

			$this->collect_children( $term_id, $depth + 1, $path, $by_parent, $ordered, $visited );
		}
	}
}
