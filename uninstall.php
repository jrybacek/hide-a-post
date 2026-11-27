<?php
/**
 * Uninstall routine for Hide A Post.
 *
 * Removes plugin options only when the plugin is deleted from WordPress.
 *
 * @package Hide_A_Post
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	$hap_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $hap_site_ids as $hap_site_id ) {
		switch_to_blog( (int) $hap_site_id );
		delete_option( 'hap' );
		delete_option( 'hap_db_version' );
		restore_current_blog();
	}
} else {
	delete_option( 'hap' );
	delete_option( 'hap_db_version' );
}
