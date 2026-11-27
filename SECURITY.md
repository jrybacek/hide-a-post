# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 2.x | Yes |
| 1.x | No. Upgrade to 2.0.0 or later. |

## Reporting a vulnerability

Report security issues privately through [GitHub private vulnerability reporting](https://github.com/jrybacek/hide-a-post/security/advisories/new). Do not open a public issue, pull request, or WordPress.org support topic.

For this plugin, a vulnerability is usually any way for someone who is not an Administrator to see a hidden post: its title, content, excerpt, comments, slug, or link. Include:

- the plugin, WordPress, and PHP versions, and whether the site is Multisite
- whether the visitor was logged in, and their role
- the URL, REST request, or steps that show the hidden post

The [documented limitations](https://github.com/jrybacek/hide-a-post#limitations) are not vulnerabilities. One example: post counts are not adjusted.

Fixes are released on WordPress.org and announced in a [GitHub Security Advisory](https://github.com/jrybacek/hide-a-post/security/advisories).
