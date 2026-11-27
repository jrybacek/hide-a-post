=== Hide A Post ===
Contributors: jrybacek
Donate link: https://www.paypal.me/jrybacek
Tags: hide, posts, category, tabletop gaming, pathfinder
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin to hide posts from non-Administrators on the site, in search, and in WordPress admin by category.

== Description ==

Hide A Post lets Administrators keep selected categories—as well as default-category (Uncategorized) posts authored by Administrators—hidden from Editors and other non-Administrator users across the site, search results, WordPress admin, and direct REST API / post-ID access.

= Hide A Post Settings =

Go to **Settings → Hide A Post** and check the categories you want hidden from non-Administrators (for example, **Awesome** and **Spicy**).

= Example Posts (seen by Administrators) =

1. Tomato (Owner: Administrator, Categories: Fruit, Red)
2. Potato (Owner: Administrator, Categories: Vegetable, White)
3. Broccoli (Owner: Administrator, Categories: Vegetable, Green)
4. Mushrooms (Owner: Administrator, Categories: Vegetable, White, Awesome) - Hidden from non-Administrators via "Awesome".
5. Apple (Owner: Administrator, Categories: Fruit, Red)
6. Pear (Owner: Administrator, Categories: Fruit, Green, Awesome) - Hidden from non-Administrators via "Awesome".
7. Peppers (Owner: Administrator, Categories: Vegetable, Green, Spicy) - Hidden from non-Administrators via "Spicy".
8. Peach (Owner: Administrator, Categories: Uncategorized) - Hidden from non-Administrators as an Administrator-authored default-category post.
9. Blueberries (Owner: Administrator, Categories: Fruit, Blue, Uncategorized) - Still hidden while in the default category.
10. Raspberries (Owner: Editor, Categories: Fruit, Red, Awesome) - Hidden from non-Administrators via "Awesome"; visible to Administrators.

= Example Posts (seen by Editors) =

1. Tomato (Owner: Administrator, Categories: Fruit, Red)
2. Potato (Owner: Administrator, Categories: Vegetable, White)
3. Broccoli (Owner: Administrator, Categories: Vegetable, Green)
4. Apple (Owner: Administrator, Categories: Fruit, Red)

= About the plugin =

We wanted to use WordPress with our tabletop gaming group. This enables everyone to collaborate on content creation while allowing the Dungeon Master to keep certain "secrets" hidden.

== Installation ==

1. Upload the `hide-a-post` folder to your `/wp-content/plugins/` directory (or upload the ZIP via **Plugins → Add New → Upload Plugin**).
2. Activate **Hide A Post** through the **Plugins** screen in WordPress.
3. Navigate to **Settings → Hide A Post** and select the categories to hide.

== Frequently Asked Questions ==

= Why did you create this? =

To avoid installing 15 different plugins just to hide posts by category from non-Administrators.

= Does this plugin work with WP-CLI? =

Yes. Starting in 2.0.0, Hide A Post safely handles WP-CLI's scoped loader and bypasses post filtering for unauthenticated WP-CLI commands (such as `wp post list`). Passing `--user=<editor>` to WP-CLI applies non-Administrator filtering as expected.

= What is hidden, and what is not? =

Hidden posts are removed from post lists, search, feeds, sitemaps, archives, previous/next links, the REST API, oEmbed, and wp-admin, and their comments are hidden too. Post counts are not adjusted: category counts and the "All (N)" totals in wp-admin still include hidden posts. Selecting a parent category does not hide posts that are only in its child categories. Pages and custom post types are never hidden.

= Can developers change who sees hidden posts? =

Yes. Use the `hap_user_can_view_hidden_posts` filter to grant or deny access per user, `hap_hide_admin_default_category_posts` to turn off the default-category rule, and `hap_administrator_ids` to change whose default-category posts are hidden.

== Screenshots ==

1. Posts as an Administrator
2. Posts as an Editor
3. Hide A Post settings page

== Changelog ==

= 2.0.0 =
* Modernized plugin architecture for WordPress 7.0+ (tested up to 7.1) and PHP 7.4–8.5.
* Fixed fatal `TypeError` crash when listing posts via WP-CLI.
* Replaced comma-separated category text input with hierarchical category checkboxes on the settings screen.
* Replaced hardcoded `wp_` table prefixes and raw SQL interpolation with dynamic `$wpdb` table names, qualified columns, and parameterized `NOT EXISTS` queries via `$wpdb->prepare()`.
* Blocked direct access to hidden posts by ID: capabilities (`map_meta_cap`, including `WP_Post` arguments), REST API reads of posts and their sub-resources (404 before the endpoint runs), oEmbed, and canonical/404 slug-guess redirects.
* Hid comments on hidden posts from comment queries, the REST API, the comments feed, and Recent Comments, and closed new comments on them.
* Excluded hidden posts from previous/next post links, `wp_get_archives()`, and `get_posts()` calls that use `suppress_filters`.
* Treated every user with the `manage_options` capability (plus Super Admins on Multisite) as an Administrator for the default-category rule.
* Removed deleted categories from the saved settings automatically.
* Added the `hap_user_can_view_hidden_posts`, `hap_should_filter_query`, `hap_hide_admin_default_category_posts`, and `hap_administrator_ids` filters.
* Added PHPUnit integration tests (single site and Multisite) to CI.
* Replaced deprecated `screen_icon()` and role-name capability checks with modern Settings API markup and `manage_options` capability checks.
* Moved option cleanup from deactivation hook to `uninstall.php` so deactivating the plugin preserves saved settings.
* Used WordPress's `default_category` option instead of hardcoding the English string "Uncategorized".

= 1.1 =
* Enhanced code to allow for more than one administrator.
* Improved SQL statements.
* Fixed bug with duplicate posts being displayed.

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
Requires WordPress 7.0+ and PHP 7.4+ (PHP 8.5 and WordPress 7.1 recommended). Fixes the WP-CLI crash, hardens SQL and direct post access, and updates the settings screen to use category checkboxes. Behavior change: Administrator-authored posts are now hidden in your site's *default category* (Settings → Writing), not only in "Uncategorized"; check that setting before upgrading. Upgrade in place (replace the plugin files); version 1.x deletes its settings when deactivated, so deactivating 1.x first means re-selecting your categories.
