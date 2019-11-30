![hide_a_post](https://github.com/jrybacek/hide-a-post/raw/master/images/logo.png)
# Hide Posts by Category

Contributors: jrybacek
Donate link: https://www.paypal.me/jrybacek
Tags: hide, posts, category, tabletop gaming, pathfinder
Requires at least: 5.0
Tested up to: 5.3
Stable tag: 1.0

A WordPress plugin to hide posts from non-administrators by category.

## Description

This plugin enables Editors to see only their content and categorized content.  Uncategorized posts from Administrators do not show up for Editors.

### Hide A Post Settings

Typed in the categories that I don't want to have seen:

 Awesome, Spicy

### Example Posts (seen from Administrators)

1. Tomato (Owner: Administrator, Categories: Fruit, Red)
2. Potato (Owner: Administrator, Categories: Vegetable, White)
3. Broccoli (Owner: Administrator, Categories: Vegetable, Green)
4. Mushrooms (Owner: Administrator, Categories: Vegetable, White, Awesome) - This is a post, I want to hide from other non-Administrators based on the "Awesome" category.
5. Apple (Owner: Administrator, Catgories: Fruit, Red)
6. Pear (Owner: Administrator, Categories: Fruit, Green, Awesome) - This is a post, I want to hide from other non-Administrators based on the "Awesome" category.
7. Peppers (Owner: Administrator, Categories: Vegetable, Green, Spicy) - This is a post, I want to hide from other non-Administrators based on the "Spicy" category.
8. Peach (Owner: Administrator, Categories: Uncategorized) - I've just started creating this post as an Administrator
9. Blueberries (Owner: Administrator, Categories: Fruit, Blue, Uncategorized) - Even thought I've started adding categories to this post, it won't show up based on the "Uncategorized" category.
10. Raspberries (Owner: Editor, Categories: Fruit, Red, Awesome) - Since I'm an administrator, I can still see this post, even though its in the "Awesome" category.

### Example Posts (seen from Editors)

1. Tomato (Owner: Administrator, Categories: Fruit, Red)
2. Potato (Owner: Administrator, Categories: Vegetable, White)
3. Broccoli (Owner: Administrator, Categories: Vegetable, Green)
5. Apple (Owner: Administrator, Catgories: Fruit, Red)
10. Raspberries (Owner: Editor, Categories: Fruit, Red, Awesome) - Since I'm an administrator, I can still see this post, even though its in the "Awesome" category.

### About this plugin

We wanted to use WordPress with our tabletop gaming group.  This enables us to all collaborate on content creation, while enabling the dungeon master to retain some "secrets".

**Why did you create this?**

So tired of using 15 plugins to achieve something very simple.

## Installation

Extract the zip file and just drop the contents in the wp-content/plugins/ directory of your WordPress installation and then activate the Plugin from Plugins page.

## Screenshots

![modal window](https://github.com/jrybacek/hide-a-post/raw/master/images/screenshot-1.png)  
1. Modal option

![options page](https://github.com/jrybacek/hide-a-post/raw/master/images/screenshot-2.png)  
2. Options page

## Changelog
**1.0**  
- Initial release.
