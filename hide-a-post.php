<?php
/**
 * Plugin Name: Hide A Post 
 * Plugin URI: https://github.com/jrybacek/hide-a-post/
 * Description: WordPress plugin that hides posts on the site, search, and WordPress admin from non-Administrators using one or more categories.
 * Version: 1.1
 * Author: Joe Rybacek
 * Author URI: https://github.com/jrybacek
 */

// Make sure we don't expose any info if called directly
if(!function_exists('add_action')) {
  echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
  exit;
}

define('HAP_DISPLAYNAME', 'Hide A Post');
define('HAP_FILE', plugin_basename(__FILE__));
define('HAP_PATH', 'options-general.php?page=hap');
$hap_options = get_option('hap');

function hap_options_page() {
  global $hap_options;
  ?>
	<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php _e(HAP_DISPLAYNAME, 'hap'); ?></h2>
		<form method="POST" action="options.php">
			<?php settings_fields('hap_options'); ?>
			<table class="form-table">
				<tbody>
					<tr valign="top">
						<th scope="row"><?php _e('What category or categories should be hidden from the editors?', 'hap'); ?></th>
						<td>
							<p>
								<?php
									if ( $hap_options['categories'] ) {
										$hap_options['categories'] = hap_options_categories_to_name($hap_options['categories']);
									} else {
										$hap_options['categories'] = __( '', 'hap' );
									}
								?>
								<input type="text" id="hap-categories" name="hap[categories]" width="15" value="<?php echo $hap_options['categories']; ?>" /><br />
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

function hap_menu() {
	add_submenu_page('options-general.php', __(HAP_DISPLAYNAME, 'hap'), __(HAP_DISPLAYNAME, 'hap'), 'administrator', 'hap', 'hap_options_page');
}
add_action('admin_menu', 'hap_menu');

function hap_admin_init() {
	register_setting('hap_options', 'hap', 'hap_validate');
}
add_action('admin_init', 'hap_admin_init');

function hap_admin_notices() {
	global $pagenow;
	if($pagenow == 'plugins.php' && current_user_can('administrator') && !hap_configured()) {
		?>
		<div class="error">
			<p><?php printf( __( 'Please configure the categories in the <a href="%s">settings menu</a> in order to start using ' . HAP_DISPLAYNAME . ' plugin.', 'hap' ), esc_url( HAP_PATH ) ); ?></p>
		</div>
		<?php
	}
}

function hap_configured() {
  global $hap_options;
	$configured = true;
	if(count($hap_options['categories']) == 0) {
		$configured = false;
	}
	return $configured;
}

function hap_activation() {
	// Set default option values
	$default = [ 'categories' => [] ];
	// Write those values to the database
	add_option('hap', $default);
}

function hap_deactivation() {
	// Remove options from the database
	delete_option('hap');
}

if( is_admin() ) {
	register_activation_hook(__FILE__, 'hap_activation');
	register_deactivation_hook(__FILE__, 'hap_deactivation');
	add_action('admin_notices', 'hap_admin_notices');
}

function hap_action_links($links, $file) {
	if( $file === HAP_FILE && current_user_can('manage_options')) {
		$settings = '<a href="' . admin_url(HAP_PATH) . '">' . esc_html__('Settings', 'hap') . '</a>';
		array_unshift($links, $settings);
	}
	return $links;
}

add_filter('plugin_action_links', 'hap_action_links', 10, 2);

function hap_validate($input) {
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

function hap_options_categories_to_name($input) {
	foreach( $input as &$category) {
		$category_name = get_cat_name($category);
		if ( !$category_name == '') {
			$category = $category_name;
		}
	}
	return implode( ', ', $input );
}

add_filter('posts_distinct', 'hap_posts_distinct', 10);
add_filter('posts_where', 'hap_posts_where', 10, 2);

function hap_posts_distinct() {
	return 'DISTINCT';
}

function hap_posts_where($where, $query) {
  global $hap_options, $pagenow;
	if(count($hap_options['categories']) > 0) {
		if(!current_user_can('administrator')) {
			$uncategorized_id = get_cat_id('Uncategorized');
			if($uncategorized_id > 0) {
				$administrators = get_users(['role__in' => ['administrator']]);
				foreach($administrators as $user) {
					$admin_ids[]= $user->ID;
				}
				$where .= " AND id NOT IN (
SELECT id
FROM wp_posts
LEFT JOIN wp_term_relationships as wtr ON (wp_posts.ID = wtr.object_id)
LEFT JOIN wp_term_taxonomy as wtt ON (wtr.term_taxonomy_id = wtt.term_taxonomy_id)
WHERE 1=1 AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'future' OR wp_posts.post_status = 'draft' OR wp_posts.post_status = 'pending' OR wp_posts.post_status = 'private')
AND wp_posts.post_author IN (" . implode(',', $admin_ids) . ") AND wtt.taxonomy = 'category' AND wtt.term_id = " . $uncategorized_id . ")";
			}
			$where .= " AND id NOT IN (
SELECT id
FROM wp_posts
LEFT JOIN wp_term_relationships as wtr ON (wp_posts.ID = wtr.object_id)
LEFT JOIN wp_term_taxonomy as wtt ON (wtr.term_taxonomy_id = wtt.term_taxonomy_id)
WHERE 1=1 AND wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'future' OR wp_posts.post_status = 'draft' OR wp_posts.post_status = 'pending' OR wp_posts.post_status = 'private')
AND wtt.taxonomy = 'category' AND wtt.term_id IN (" . implode(',', $hap_options['categories']) . "))";
		}
	}
	return $where;
}

