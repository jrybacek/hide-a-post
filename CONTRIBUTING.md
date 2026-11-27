# Contributing to Hide A Post

Thanks for your interest in contributing to **Hide A Post**!

## Commit Message Convention

This repository combines [Gitmoji](https://gitmoji.dev/) with [Conventional Commits v1.0.0](https://www.conventionalcommits.org/en/v1.0.0/) for both instant visual scannability and automated changelog generation with [git-cliff](https://git-cliff.org/).

### Format

```text
<gitmoji> <type>[optional (<scope>)][optional !]: <description>

[optional body]

[optional footer(s)]
```

* Use the imperative, present tense in `<description>` (e.g., `add`, `fix`, `update` — not `added` or `fixes`).
* Keep the header concise (ideally under 72 characters).
* Append `!` after the type/scope (or include a `BREAKING CHANGE:` footer) for breaking changes, paired with `💥`.

### Gitmoji + Conventional Commit Mapping

| Gitmoji | Shortcode | Type | SemVer | Description |
| :---: | :--- | :--- | :---: | :--- |
| ✨ | `:sparkles:` | `feat` | Minor | Introduce a new feature |
| 🐛 | `:bug:` | `fix` | Patch | Fix a bug |
| 🚑️ | `:ambulance:` | `fix` | Patch | Critical hotfix |
| 🔒️ | `:lock:` | `fix` / `security` | Patch | Fix security or privacy issues |
| ⚡️ | `:zap:` | `perf` | Patch | Improve performance |
| ♻️ | `:recycle:` | `refactor` | — | Refactor code without changing behavior |
| 📝 | `:memo:` | `docs` | — | Add or update documentation |
| 💄 | `:lipstick:` | `style` | — | Formatting, whitespace, UI/CSS polish |
| ✅ | `:white_check_mark:` | `test` | — | Add, update, or pass tests |
| 📦️ | `:package:` | `build` | — | Build system, packaging, or dependencies |
| 👷 | `:construction_worker:` | `ci` | — | CI/CD configuration and workflows |
| 🔧 | `:wrench:` | `chore` | — | Configuration, tooling, and maintenance |
| ⏪️ | `:rewind:` | `revert` | — | Revert a previous commit |
| 💥 | `:boom:` | `feat!` / `fix!` | Major | Introduce breaking changes |

### Examples

```text
✨ feat(settings): replace text input with category checkboxes
🐛 fix(wp-cli): prevent fatal TypeError when listing posts via WP-CLI
🔒️ fix(sql): use $wpdb->prepare and dynamic table prefixes
📝 docs(readme): modernize README using standard-readme
🔧 chore(changelog): configure git-cliff for changelog generation
💥 feat(core)!: raise minimum requirements to PHP 8.0 and WordPress 6.0
```

## Local Git Setup (Recommended)

Enable the included commit message validation hook and commit template:

```bash
git config core.hooksPath .githooks
git config commit.template .gitmessage
```

The `.githooks/commit-msg` hook validates that every commit header follows the `<gitmoji> <type>(<scope>): <description>` format (while allowing `Merge`, `Revert`, `fixup!`, and `squash!` commits).

## Generating the Changelog

This project uses [git-cliff](https://git-cliff.org/) configured via [cliff.toml](cliff.toml).

```bash
# Preview the full changelog in stdout
npx git-cliff

# Update CHANGELOG.md (including unreleased changes)
npx git-cliff -o CHANGELOG.md

# Generate CHANGELOG.md for a new release tag (e.g., v2.0.0)
npx git-cliff --tag v2.0.0 -o CHANGELOG.md
```

If you have the native `git-cliff` binary installed, you can run `git-cliff` directly in place of `npx git-cliff`.

## Documentation Style

When editing `README.md`, please follow the [standard-readme](https://github.com/richardlitt/standard-readme) specification.
