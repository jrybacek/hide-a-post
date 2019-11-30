<?php
/**
 * Plugin Name: Hide A Post 
 * Plugin URI: https://github.com/jrybacek/hide-a-post/
 * Description: WordPress plugin that hides posts on the site, search, and WordPress admin from non-Administrators using one or more categories.
 * Version: 0.1
 * Author: Joe Rybacek
 * Author URI: http://www.rybacek-consulting.com
 */

// Make sure we don't expose any info if called directly
if(!function_exists('add_action')) {
  echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
  exit;
}

define('HAP_FILE', plugin_basename(__FILE__));
define('HAP_PATH', 'options-general.php?page=ema');
$ema_options = get_option('ema');

function ema_options_page() {
  global $ema_options;
  ?>
	<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php _e( 'Easy Multi-User Authoring', 'ema' ); ?></h2>
		<form method="POST" action="options.php">
			<?php settings_fields( 'ema_options' ); ?>
			<table class="form-table">
				<tbody>
					<tr valign="top">
						<th scope="row"><?php _e( 'What category or categories should be hidden from the editors?', 'ema' ); ?></th>
						<td>
							<p>
								<?php
									if ( $ema_options['categories'] ) {
										$ema_options['categories'] = ema_options_categories_to_name($ema_options['categories']);
									} else {
										$ema_options['categories'] = __( '', 'ema' );
									}
								?>
								<input type="text" id="ema-categories" name="ema[categories]" width="15" value="<?php echo $ema_options['categories']; ?>" /><br />
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
			<input type="hidden" name="ap-core-settings-submit" value="Y" />
		</form>
	</div>
	<?php
}

function ema_menu() {
	add_submenu_page('options-general.php', __('Easy Multi-User Authoring', 'ema'), __('Easy Multi-User Authoring', 'ema'), 'administrator', 'ema', 'ema_options_page');
}
add_action('admin_menu', 'ema_menu');

function ema_admin_init() {
	register_setting('ema_options', 'ema', 'ema_validate');
}
add_action('admin_init', 'ema_admin_init');

function ema_admin_notices() {
	global $pagenow;
	if($pagenow == 'plugins.php' && current_user_can('administrator') && !ema_configured()) {
		?>
		<div class="error">
			<p><?php printf( __( 'Please configure the categories in the <a href="%s">settings menu</a> in order to start using Easy Multi-User Authoring plugin.', 'ema' ), esc_url( HAP_PATH ) ); ?></p>
		</div>
		<?php
	}
}

function ema_configured() {
  global $ema_options;
	$configured = true;
	if(count($ema_options['categories']) == 0) {
		$configured = false;
	}
	return $configured;
}

function ema_activation() {
	// Set default option values
	$default = [ 'categories' => [] ];
	// Write those values to the database
	add_option('ema', $default);
}

function ema_deactivation() {
	// Remove options from the database
	delete_option('ema');
}

if( is_admin() ) {
	register_activation_hook(__FILE__, 'ema_activation');
	register_deactivation_hook(__FILE__, 'ema_deactivation');
	add_action('admin_notices', 'ema_admin_notices');
}

function ema_action_links($links, $file) {
	if( $file === HAP_FILE && current_user_can('manage_options')) {
		$settings = '<a href="' . admin_url(HAP_PATH) . '">' . esc_html__('Settings', 'ema') . '</a>';
		array_unshift($links, $settings);
	}
	return $links;
}

add_filter('plugin_action_links', 'ema_action_links', 10, 2);

function ema_validate($input) {
	$categories = [];
	$categories_valid = true;
	foreach( explode( ',', $input['categories'] ) as $category ) {
		if( term_exists( trim($category), 'category' ) ) {
			$categories[] = get_cat_ID(trim($category));
		} else {
			$categories_valid = false;
		}
	}
	if ( $categories_valid ) {
		$input['categories'] = $categories;
	} else {
		$input['categories'] = null;
	}
	return $input;
}

function ema_options_categories_to_name($input) {
	foreach( $input as &$category) {
		$category_name = get_cat_name($category);
		if ( !$category_name == '') {
			$category = $category_name;
		}
	}
	return implode( ', ', $input );
}

add_filter( 'posts_join', 'ema_posts_join', 10, 2 );
add_filter( 'posts_where', 'ema_posts_where', 10, 2 );

function ema_posts_join($join, $query) {
	global $ema_options, $pagenow, $wpdb;
	if(count($ema_options['categories']) > 0) {
		$user_id = get_current_user_id();
		$user_meta = get_userdata($user_id);
		$user_roles = $user_meta->roles;
		if (!in_array('administrator', $user_roles)) {
			$join .= " LEFT JOIN $wpdb->term_relationships as wtr ON ($wpdb->posts.ID = wtr.object_id) ";
			$join .= " LEFT JOIN $wpdb->term_taxonomy as wtt ON (wtr.term_taxonomy_id = wtt.term_taxonomy_id) ";
			$join .= " LEFT JOIN $wpdb->terms as wt ON(wtt.term_id = wt.term_id) ";
		}
	}
	return $join;
}

function ema_posts_where($where, $query) {
  global $ema_options, $pagenow;
	if(count($ema_options['categories']) > 0) {
		$user_id = get_current_user_id();
		$user_meta = get_userdata($user_id);
		$user_roles = $user_meta->roles;
		if (!in_array('administrator', $user_roles)) {
			$where .= " AND NOT (wp_posts.post_author IN (1) AND wtt.taxonomy IN ('category') AND wt.name = 'Uncategorized')";
			$where .= " AND id NOT IN (
SELECT id
FROM wp_posts
LEFT JOIN wp_term_relationships as wtr ON (wp_posts.ID = wtr.object_id)
LEFT JOIN wp_term_taxonomy as wtt ON (wtr.term_taxonomy_id = wtt.term_taxonomy_id)
LEFT JOIN wp_terms as wt ON(wtt.term_id = wt.term_id)
WHERE 1=1 AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'future' OR wp_posts.post_status = 'draft' OR wp_posts.post_status = 'pending' OR wp_posts.post_status = 'private')
AND wtt.taxonomy IN ('category') AND wt.term_id IN (" . implode( ',', $ema_options['categories']) . "))";
		}
	}
	return $where;
}

