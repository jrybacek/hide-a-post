# Hide A Post

[![standard-readme compliant](https://img.shields.io/badge/readme%20style-standard-brightgreen.svg?style=flat-square)](https://github.com/richardlitt/standard-readme)
[![Conventional Commits](https://img.shields.io/badge/Conventional%20Commits-1.0.0-%23FE5196?logo=conventionalcommits&logoColor=white&style=flat-square)](https://www.conventionalcommits.org/en/v1.0.0/)
[![gitmoji](https://img.shields.io/badge/gitmoji-%20😜%20😍-FFDD67.svg?style=flat-square)](https://gitmoji.dev)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg?style=flat-square)](LICENSE)

> WordPress plugin that hides posts on the site, search, and WordPress admin from non-Administrators using one or more categories.

Hide A Post lets Administrators keep selected categories—as well as default-category (`Uncategorized`) posts authored by Administrators—hidden from Editors and other non-Administrator users across the site, search results, WordPress admin, and direct post-ID / REST API access.

## Table of Contents

- [Background](#background)
- [Install](#install)
- [Usage](#usage)
- [Screenshots](#screenshots)
- [Maintainers](#maintainers)
- [Contributing](#contributing)
- [License](#license)

## Background

This plugin was created for a tabletop gaming group using WordPress to collaborate on campaign content while letting the Dungeon Master keep certain "secrets" hidden from players—without needing to stack 15 different plugins to accomplish something simple.

## Install

### Requirements

- **WordPress:** 7.0+ (recommended: 7.1)
- **PHP:** 7.4+ (recommended: 8.5)

### Manual Installation

1. Download the plugin archive from the [GitHub repository](https://github.com/jrybacek/hide-a-post).
2. Extract the archive into your WordPress `wp-content/plugins/` directory (or upload the `.zip` file via **Plugins → Add New → Upload Plugin**).
3. Activate **Hide A Post** from the **Plugins** screen in WordPress admin (or run `wp plugin activate hide-a-post`).

## Usage

1. In WordPress admin, go to **Settings → Hide A Post**.
2. Check one or more categories to hide from non-Administrators (for example, **Awesome** and **Spicy**) and click **Save Changes**.

Once configured:

- Posts assigned to any checked hidden category are hidden from non-Administrators (including direct post-ID access in wp-admin and the REST API).
- Posts authored by an Administrator that are assigned to the site's default category (typically `Uncategorized`) are hidden from non-Administrators until recategorized.
- Administrators (`manage_options` capability) continue to see all posts.
- Unauthenticated WP-CLI commands (such as `wp post list`) bypass filtering automatically so CLI administration works cleanly.

### Example

With **Awesome** and **Spicy** checked:

#### Posts Seen by Administrators

| Post | Author Role | Categories | Visibility for Editors |
| :--- | :--- | :--- | :--- |
| Tomato | Administrator | Fruit, Red | Visible |
| Potato | Administrator | Vegetable, White | Visible |
| Broccoli | Administrator | Vegetable, Green | Visible |
| Mushrooms | Administrator | Vegetable, White, Awesome | Hidden (`Awesome`) |
| Apple | Administrator | Fruit, Red | Visible |
| Pear | Administrator | Fruit, Green, Awesome | Hidden (`Awesome`) |
| Peppers | Administrator | Vegetable, Green, Spicy | Hidden (`Spicy`) |
| Peach | Administrator | Uncategorized | Hidden (Admin + default category) |
| Blueberries | Administrator | Fruit, Blue, Uncategorized | Hidden (Admin + default category) |
| Raspberries | Editor | Fruit, Red, Awesome | Hidden (`Awesome`) |

#### Posts Seen by Editors

- **Tomato** — Fruit, Red
- **Potato** — Vegetable, White
- **Broccoli** — Vegetable, Green
- **Apple** — Fruit, Red

## Screenshots

1. **Posts as an Administrator**

   ![Example Posts as an Administrator](images/screenshot-1.png)

2. **Posts as an Editor**

   ![Example Posts as an Editor](images/screenshot-2.png)

3. **Hide A Post Settings Page**

   ![Hide A Post settings](images/screenshot-3.png)

## Maintainers

[@jrybacek](https://github.com/jrybacek)

## Contributing

Issues and pull requests are welcome!

- **Commits:** This project uses combined [Gitmoji](https://gitmoji.dev/) and [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) (`<gitmoji> <type>(<scope>): <description>`). See [CONTRIBUTING.md](CONTRIBUTING.md) for the full emoji mapping, examples, and local git hook setup.
- **Code Quality:** Run `composer phpcs` (WordPress Coding Standards + PHPCompatibilityWP for PHP 7.4–8.5) and `composer phpstan` before opening a pull request.
- **Changelog:** Release notes in [CHANGELOG.md](CHANGELOG.md) are generated with [git-cliff](https://git-cliff.org/) using [cliff.toml](cliff.toml).
- **README:** If editing this README, please conform to the [standard-readme](https://github.com/richardlitt/standard-readme) specification.

## License

[GPL-2.0-or-later](LICENSE) © Joe Rybacek
