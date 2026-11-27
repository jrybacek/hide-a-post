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
 * Coordinates plugin lifecycle, option retrieval, and admin UI hooks.
 *
 * @since 2.0.0
 */
final class HAP_Plugin {

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

		$this->settings     = new HAP_Settings();
		$this->query_filter = new HAP_Query_Filter();

		$this->settings->init();
		$this->query_filter->init();

		add_filter( 'plugin_action_links_' . HAP_BASENAME, array( $this, 'add_action_links' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		}
	}

	/**
	 * Handles plugin activation.
	 *
	 * Initializes the default option structure if missing and repairs legacy
	 * options where `categories` may have been stored as `null`.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function activate() {
		$existing = get_option( HAP_OPTION_NAME, false );

		if ( false === $existing ) {
			add_option( HAP_OPTION_NAME, array( 'categories' => array() ) );
			return;
		}

		if ( ! is_array( $existing ) || ! isset( $existing['categories'] ) || ! is_array( $existing['categories'] ) ) {
			update_option( HAP_OPTION_NAME, array( 'categories' => self::normalize_category_ids( $existing ) ) );
		}
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
		$options = get_option( HAP_OPTION_NAME, array() );

		return self::normalize_category_ids( $options );
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
			if ( '' !== $trimmed && ctype_digit( $trimmed ) ) {
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
