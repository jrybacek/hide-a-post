![Hide A Post logo](https://github.com/jrybacek/hide-a-post/raw/master/images/logo.png)
# Hide Posts by Category

Contributors: jrybacek
Donate link: https://www.paypal.me/jrybacek
Tags: hide, posts, category, tabletop gaming, pathfinder
Requires at least: 5.0
Tested up to: 5.3
Stable tag: 1.1

A WordPress plugin to hide posts from non-administrators by category.

## Description

This plugin enables Editors to see only their content and categorized content.  Uncategorized posts from Administrators do not show up for Editors.

### Hide A Post Settings

Typed in the categories that I don't want to have seen:

    Awesome, Spicy

### Example Posts (seen from Administrators)

* Tomato (Owner: Administrator, Categories: Fruit, Red)
* Potato (Owner: Administrator, Categories: Vegetable, White)
* Broccoli (Owner: Administrator, Categories: Vegetable, Green)
* Mushrooms (Owner: Administrator, Categories: Vegetable, White, Awesome) - This is a post, I want to hide from other non-Administrators based on the "Awesome" category.
* Apple (Owner: Administrator, Catgories: Fruit, Red)
* Pear (Owner: Administrator, Categories: Fruit, Green, Awesome) - This is a post, I want to hide from other non-Administrators based on the "Awesome" category.
* Peppers (Owner: Administrator, Categories: Vegetable, Green, Spicy) - This is a post, I want to hide from other non-Administrators based on the "Spicy" category.
* Peach (Owner: Administrator, Categories: Uncategorized) - I've just started creating this post as an Administrator
* Blueberries (Owner: Administrator, Categories: Fruit, Blue, Uncategorized) - Even thought I've started adding categories to this post, it won't show up based on the "Uncategorized" category.
* Raspberries (Owner: Editor, Categories: Fruit, Red, Awesome) - Since I'm an administrator, I can still see this post, even though its in the "Awesome" category.

### Example Posts (seen from Editors)

* Tomato (Owner: Administrator, Categories: Fruit, Red)
* Potato (Owner: Administrator, Categories: Vegetable, White)
* Broccoli (Owner: Administrator, Categories: Vegetable, Green)
* Apple (Owner: Administrator, Catgories: Fruit, Red)
* Raspberries (Owner: Editor, Categories: Fruit, Red, Awesome) - Since I'm an administrator, I can still see this post, even though its in the "Awesome" category.

### About this plugin

We wanted to use WordPress with our tabletop gaming group.  This enables us to all collaborate on content creation, while enabling the dungeon master to retain some "secrets".

**Why did you create this?**

So tired of using 15 plugins to achieve something very simple.

## Installation

Extract the zip file and just drop the contents in the wp-content/plugins/ directory of your WordPress installation and then activate the Plugin from Plugins page.

## Screenshots

![Example Posts as an Administrator](https://github.com/jrybacek/hide-a-post/raw/master/images/screenshot-1.png)  
1. Posts as an Administrator
![Example Posts as an Editor](https://github.com/jrybacek/hide-a-post/raw/master/images/screenshot-2.png)  
2. Posts as an Editor
![Hide A Post settings](https://github.com/jrybacek/hide-a-post/raw/master/images/screenshot-3.png)  
3. Hide A Post settings page

## Changelog
**1.1**
- Enhanced code to allow for more than one administrator
- Improved poorly written SQL statements
- Fixed bug duplicate posts being displayed

**1.0**  
- Initial release.
