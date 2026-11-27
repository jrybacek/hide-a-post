<?php
/**
 * Integration tests for Hide A Post settings, upgrades, and helpers.
 *
 * @package Hide_A_Post
 */

/**
 * Covers option sanitizing, legacy migration, and category cleanup.
 */
class HAP_Test_Settings extends WP_UnitTestCase {

	/**
	 * Hidden category ID.
	 *
	 * @var int
	 */
	private $secret_cat;

	/**
	 * Second category ID.
	 *
	 * @var int
	 */
	private $public_cat;

	/**
	 * Creates the test categories.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->secret_cat = self::factory()->category->create( array( 'name' => 'Secret' ) );
		$this->public_cat = self::factory()->category->create( array( 'name' => 'Public' ) );
	}

	/**
	 * Checkbox payloads keep valid IDs and drop unknown or malformed values.
	 *
	 * @return void
	 */
	public function test_sanitize_checkbox_payload() {
		$settings = new HAP_Settings();

		$result = $settings->sanitize_options(
			array(
				'submitted'  => '1',
				'categories' => array( (string) $this->secret_cat, $this->public_cat, '999999', '-3', '1.5', array( 7 ), (string) $this->secret_cat ),
			)
		);

		$this->assertSame( array( $this->secret_cat, $this->public_cat ), $result['categories'] );
	}

	/**
	 * Unchecking every box clears the setting.
	 *
	 * @return void
	 */
	public function test_sanitize_all_unchecked() {
		$settings = new HAP_Settings();

		$this->assertSame( array( 'categories' => array() ), $settings->sanitize_options( array( 'submitted' => '1' ) ) );
		$this->assertSame( array( 'categories' => array() ), $settings->sanitize_options( 'garbage' ) );
		$this->assertSame( array( 'categories' => array() ), $settings->sanitize_options( array( 'categories' => 42 ) ) );
	}

	/**
	 * Legacy comma-separated category names are resolved to IDs.
	 *
	 * @return void
	 */
	public function test_sanitize_legacy_name_string() {
		$settings = new HAP_Settings();

		$result = $settings->sanitize_options( array( 'categories' => 'Secret, Nope ,Public' ) );

		$this->assertSame( array( $this->secret_cat, $this->public_cat ), $result['categories'] );
	}

	/**
	 * Stored v1.x name strings are migrated on upgrade.
	 *
	 * @return void
	 */
	public function test_upgrade_migrates_legacy_name_string() {
		update_option( 'hap', array( 'categories' => 'Secret, Public' ) );
		delete_option( HAP_Plugin::DB_VERSION_OPTION );

		HAP_Plugin::maybe_upgrade();

		$this->assertSame( array( $this->secret_cat, $this->public_cat ), HAP_Plugin::get_hidden_category_ids() );
		$this->assertSame( HAP_Plugin::DB_VERSION, get_option( HAP_Plugin::DB_VERSION_OPTION ) );
	}

	/**
	 * Stored v1.1 null payloads and deleted IDs are cleaned up on upgrade.
	 *
	 * @return void
	 */
	public function test_upgrade_repairs_null_and_orphaned_ids() {
		update_option( 'hap', array( 'categories' => null ) );
		delete_option( HAP_Plugin::DB_VERSION_OPTION );
		HAP_Plugin::maybe_upgrade();
		$this->assertSame( array( 'categories' => array() ), get_option( 'hap' ) );

		update_option( 'hap', array( 'categories' => array( $this->secret_cat, 999999 ) ) );
		delete_option( HAP_Plugin::DB_VERSION_OPTION );
		HAP_Plugin::maybe_upgrade();
		$this->assertSame( array( $this->secret_cat ), HAP_Plugin::get_hidden_category_ids() );
	}

	/**
	 * A fresh install gets the default option.
	 *
	 * @return void
	 */
	public function test_upgrade_creates_default_option() {
		delete_option( 'hap' );
		delete_option( HAP_Plugin::DB_VERSION_OPTION );

		HAP_Plugin::activate();

		$this->assertSame( array( 'categories' => array() ), get_option( 'hap' ) );
	}

	/**
	 * Deleting a hidden category removes it from the setting.
	 *
	 * @return void
	 */
	public function test_deleted_category_is_pruned() {
		update_option( 'hap', array( 'categories' => array( $this->secret_cat, $this->public_cat ) ) );

		wp_delete_term( $this->public_cat, 'category' );

		$this->assertSame( array( $this->secret_cat ), HAP_Plugin::get_hidden_category_ids() );
	}

	/**
	 * Strict positive integer parsing.
	 *
	 * @return void
	 */
	public function test_parse_positive_int() {
		$this->assertSame( 5, HAP_Plugin::parse_positive_int( 5 ) );
		$this->assertSame( 12, HAP_Plugin::parse_positive_int( ' 12 ' ) );
		$this->assertSame( 0, HAP_Plugin::parse_positive_int( 0 ) );
		$this->assertSame( 0, HAP_Plugin::parse_positive_int( -4 ) );
		$this->assertSame( 0, HAP_Plugin::parse_positive_int( '-4' ) );
		$this->assertSame( 0, HAP_Plugin::parse_positive_int( '4.2' ) );
		$this->assertSame( 0, HAP_Plugin::parse_positive_int( 4.0 ) );
		$this->assertSame( 0, HAP_Plugin::parse_positive_int( '1e3' ) );
		$this->assertSame( 0, HAP_Plugin::parse_positive_int( array( 1 ) ) );
		$this->assertSame( 0, HAP_Plugin::parse_positive_int( null ) );
	}

	/**
	 * The settings field renders checkboxes with the saved state.
	 *
	 * @return void
	 */
	public function test_render_categories_field() {
		update_option( 'hap', array( 'categories' => array( $this->secret_cat ) ) );

		ob_start();
		( new HAP_Settings() )->render_categories_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="hap[submitted]"', $html );
		$this->assertMatchesRegularExpression( '/id="hap-category-' . $this->secret_cat . '"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="hap-category-' . $this->public_cat . '"[^>]*checked/', $html );
	}
}
