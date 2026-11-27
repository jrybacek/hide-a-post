<?php
/**
 * Main plugin coordinator for Hide A Post.
 *
 * @package Hide_A_Post
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates plugin lifecycle, option storage and upgrades, and admin UI hooks.
 *
 * @since 2.0.0
 */
final class HAP_Plugin {

	/**
	 * Option that stores the schema version of the saved settings.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const DB_VERSION_OPTION = 'hap_db_version';

	/**
	 * Current settings schema version.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const DB_VERSION = '2.0.0';

	/**
	 * Singleton instance.
	 *
	 * @since 2.0.0
	 * @var HAP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings handler instance.
	 *
	 * @since 2.0.0
	 * @var HAP_Settings|null
	 */
	private $settings = null;

	/**
	 * Query filter handler instance.
	 *
	 * @since 2.0.0
	 * @var HAP_Query_Filter|null
	 */
	private $query_filter = null;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @since 2.0.0
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Returns the singleton plugin instance.
	 *
	 * @since 2.0.0
	 *
	 * @return HAP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton usage.
	 *
	 * @since 2.0.0
	 */
	private function __construct() {}

	/**
	 * Initializes plugin components and registers hooks.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		// Runs on every site load (not only activation) so in-place updates and
		// network-activated sites are migrated before any query is filtered.
		self::maybe_upgrade();

		$this->settings     = new HAP_Settings();
		$this->query_filter = new HAP_Query_Filter();

		$this->settings->init();
		$this->query_filter->init();

		add_action( 'delete_category', array( __CLASS__, 'prune_deleted_category' ) );

		if ( is_multisite() ) {
			// Migrate sites that are queried via switch_to_blog() before their first load.
			add_action( 'switch_blog', array( __CLASS__, 'maybe_upgrade' ), 10, 0 );
		}

		add_filter( 'plugin_action_links_' . HAP_BASENAME, array( $this, 'add_action_links' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		}
	}

	/**
	 * Handles plugin activation.
	 *
	 * Network-activated sites are initialized lazily by {@see HAP_Plugin::maybe_upgrade()}
	 * on their first load, so no per-site loop is needed here.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function activate() {
		self::maybe_upgrade();
	}

	/**
	 * Creates or migrates the stored option to the current schema once per site.
	 *
	 * Handles v1.0/v1.1 payloads: ID arrays, comma-separated category names,
	 * `null` (v1.1 validation failure), and IDs of categories deleted since.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		$existing = get_option( HAP_OPTION_NAME, false );

		if ( false === $existing ) {
			add_option( HAP_OPTION_NAME, array( 'categories' => array() ) );
		} else {
			$raw_categories = is_array( $existing ) && isset( $existing['categories'] ) ? $existing['categories'] : array();

			update_option( HAP_OPTION_NAME, array( 'categories' => self::resolve_category_ids( $raw_categories ) ) );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Resolves legacy or submitted category values into existing category term IDs.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed    $raw_categories Array of IDs, or a comma-separated string of category names.
	 * @param string[] $invalid_items  Receives the values that could not be resolved.
	 * @param-out string[] $invalid_items
	 * @return int[]
	 */
	public static function resolve_category_ids( $raw_categories, &$invalid_items = array() ) {
		$valid_ids     = array();
		$invalid_items = array();

		if ( is_string( $raw_categories ) ) {
			$names = array_filter(
				array_map( 'trim', explode( ',', sanitize_text_field( $raw_categories ) ) ),
				static function ( $name ) {
					return '' !== $name;
				}
			);

			foreach ( $names as $name ) {
				$term = get_term_by( 'name', $name, 'category' );
				if ( $term instanceof WP_Term ) {
					$valid_ids[] = (int) $term->term_id;
				} else {
					$invalid_items[] = $name;
				}
			}
		} elseif ( is_array( $raw_categories ) ) {
			foreach ( $raw_categories as $raw_value ) {
				$term_id = self::parse_positive_int( $raw_value );
				if ( $term_id > 0 && null !== term_exists( $term_id, 'category' ) ) {
					$valid_ids[] = $term_id;
				} else {
					$invalid_items[] = is_scalar( $raw_value ) ? sanitize_text_field( (string) $raw_value ) : '';
				}
			}
		}

		return array_values( array_unique( $valid_ids ) );
	}

	/**
	 * Removes a deleted category from the hidden category list.
	 *
	 * @since 2.0.0
	 *
	 * @param int $term_id Deleted category term ID.
	 * @return void
	 */
	public static function prune_deleted_category( $term_id ) {
		$term_id = (int) $term_id;
		$current = self::get_hidden_category_ids();

		if ( ! in_array( $term_id, $current, true ) ) {
			return;
		}

		update_option(
			HAP_OPTION_NAME,
			array( 'categories' => array_values( array_diff( $current, array( $term_id ) ) ) )
		);
	}

	/**
	 * Retrieves the configured hidden category term IDs.
	 *
	 * Always returns a clean, unique list of positive integer term IDs without
	 * relying on global variables (which fail under WP-CLI's scoped loader).
	 *
	 * @since 2.0.0
	 *
	 * @return int[]
	 */
	public static function get_hidden_category_ids() {
		return self::normalize_category_ids( get_option( HAP_OPTION_NAME, array() ) );
	}

	/**
	 * Strictly parses a positive integer ID without coercing arrays, floats, or negative numbers.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $value Candidate ID value.
	 * @return int Positive integer ID, or 0 if invalid.
	 */
	public static function parse_positive_int( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( 1 === preg_match( '/^[0-9]{1,18}$/', $trimmed ) ) {
				$parsed = (int) $trimmed;
				return $parsed > 0 ? $parsed : 0;
			}
		}

		return 0;
	}

	/**
	 * Normalizes a raw option payload into a unique list of positive category IDs.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $options Raw option value from the database.
	 * @return int[]
	 */
	public static function normalize_category_ids( $options ) {
		if ( ! is_array( $options ) || empty( $options['categories'] ) || ! is_array( $options['categories'] ) ) {
			return array();
		}

		$ids = array();

		foreach ( $options['categories'] as $raw_id ) {
			$term_id = self::parse_positive_int( $raw_id );
			if ( $term_id > 0 ) {
				$ids[] = $term_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Checks whether at least one hidden category is configured.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return ! empty( self::get_hidden_category_ids() );
	}

	/**
	 * Adds a Settings link to the plugin row on the Plugins screen.
	 *
	 * @since 2.0.0
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public function add_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( HAP_SETTINGS_PATH ) ),
			esc_html__( 'Settings', 'hide-a-post' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Displays an admin notice on the Plugins screen until categories are configured.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		global $pagenow;

		if ( 'plugins.php' !== $pagenow || ! current_user_can( 'manage_options' ) || self::is_configured() ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: URL to the Hide A Post settings page. */
			__( 'Please select one or more categories in the <a href="%s">Hide A Post settings</a> to start hiding posts from non-Administrators.', 'hide-a-post' ),
			esc_url( admin_url( HAP_SETTINGS_PATH ) )
		);

		echo '<div class="notice notice-warning"><p>';
		echo wp_kses(
			$message,
			array(
				'a' => array(
					'href' => array(),
				),
			)
		);
		echo '</p></div>';
	}
}
