# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-27

### ✨ Features

- **core:** [**breaking**] Modernize plugin architecture for WP 7.0+ and PHP 7.4-8.5 ([69e326b](https://github.com/jrybacek/hide-a-post/commit/69e326b834857ce5c01e8313c65da4282d7f42f5))
- **settings:** Replace category text input with hierarchical checkboxes ([2f0f856](https://github.com/jrybacek/hide-a-post/commit/2f0f85670a0881fde8e6d00959fb52d4b6d3e474))

### 🐛 Bug Fixes

- **query:** Fix WP-CLI crash, harden SQL, and block direct post-ID reads ([064033c](https://github.com/jrybacek/hide-a-post/commit/064033c7479c72526072f783a4a027e55b07bb6f))
- **settings:** Use boolean predicate in array_filter for PHPStan compliance ([f469e71](https://github.com/jrybacek/hide-a-post/commit/f469e711e1ad6547100a0cf081d66fbf0fecd38e))
- **query:** Block REST, comment, link, and redirect leaks of hidden posts ([a9a91d5](https://github.com/jrybacek/hide-a-post/commit/a9a91d50413afedead5bcd1bb1bc39ccfa7078d1))
- **settings:** Migrate legacy options and prune deleted categories ([5f38059](https://github.com/jrybacek/hide-a-post/commit/5f38059f233126b22e3fc8ae5fb59efea5c52c64))
- **query:** Close REST, comment feed, revision, and redirect gaps ([87dc1a3](https://github.com/jrybacek/hide-a-post/commit/87dc1a32a621125c215a8d2287750186f6daacec))
- **query:** Check effective REST IDs, protect hidden categories, finish saves ([68a21d4](https://github.com/jrybacek/hide-a-post/commit/68a21d49657781a484fc26654602f8e8bb18f4dc))
- **query:** Guard bulk category deletion, REST creates, and post params ([af49e04](https://github.com/jrybacek/hide-a-post/commit/af49e042eb27e3dbcae6bd3b6887cd98517d8c6a))
- **admin:** Hide titles, block post comment screen, scope Empty Trash ([23064d9](https://github.com/jrybacek/hide-a-post/commit/23064d93812512a7688bfc50aa4ba5a26fcd9c13))
- **admin:** Match core ID parsing, hide parent slugs and menu links ([c35c40c](https://github.com/jrybacek/hide-a-post/commit/c35c40c2b883fcdc254be5f653ce757829585756))
- **menus:** Filter menu output without altering stored navigation ([468904e](https://github.com/jrybacek/hide-a-post/commit/468904e0fe3ddc0df0b1021d00d2e92adc84f03f))
- **menus:** Redact hidden submenus on render and in nested REST content ([6b82aa0](https://github.com/jrybacek/hide-a-post/commit/6b82aa00e30a178d1eec0a7e677a19f0534d325f))
- **menus:** Guard Navigation refs and redact submenus before rendering ([b8a43f9](https://github.com/jrybacek/hide-a-post/commit/b8a43f976e87ba01710b696e96a87e681822b896))
- **menus:** Leave unpublished hidden submenus to core ([26af249](https://github.com/jrybacek/hide-a-post/commit/26af249cf3c842cbbbd6f581b960d7d0fed7c694))
- **menus:** Drop unpublished hidden submenus, guard navigationMenuId ([8bec13b](https://github.com/jrybacek/hide-a-post/commit/8bec13bfcac34f9f48c14e7797f3d336e5efd20c))
- **menus:** Pre-redact whole top-level block trees, keep empty labels ([abce392](https://github.com/jrybacek/hide-a-post/commit/abce392639e3652bb298b395c5be7c39213621b3))
- **menus:** Match core's empty-label test, drop unpublished submenus in REST ([4ccf5f1](https://github.com/jrybacek/hide-a-post/commit/4ccf5f10471e2a596f84e3ddf00f2edfd7362dac))
- **menus:** Resolve Navigation refs with get_post() like core ([d48da42](https://github.com/jrybacek/hide-a-post/commit/d48da42818a8fffd7e2de27fa9e08509d7c201f0))

### 📝 Documentation

- **readme:** Modernize README following standard-readme spec ([fd67913](https://github.com/jrybacek/hide-a-post/commit/fd67913f85862f2ec936bb4c16d77b25d682cdf9))
- **changelog:** Generate initial CHANGELOG.md with git-cliff ([2a81559](https://github.com/jrybacek/hide-a-post/commit/2a815591b514cd4e219f0dfc8c7400271f291336))
- **plugin:** Update README.md and readme.txt for 2.0.0 ([99a5cf5](https://github.com/jrybacek/hide-a-post/commit/99a5cf53d40ca5ce6b8697b75b850809233accc0))
- **changelog:** Update CHANGELOG.md with 2.0.0 modernization changes ([39e6242](https://github.com/jrybacek/hide-a-post/commit/39e62423284a84241ed55cd5721f20d4e7d9da66))
- **plugin:** Document hidden paths, limitations, filters, and upgrade notes ([e0d8d12](https://github.com/jrybacek/hide-a-post/commit/e0d8d124fc8604c01775debea8a579bf98f634c1))
- **screenshots:** Update settings page screenshot for 2.0.0 ([8ac9a50](https://github.com/jrybacek/hide-a-post/commit/8ac9a5010454dece63a4db4f645af66f64575556))

### 💄 Styling

- **query:** Suppress WPCS UnfinishedPrepare warning for dynamic IN placeholders ([f1f0572](https://github.com/jrybacek/hide-a-post/commit/f1f05722b9db00145d2ed20c2f00c8cb7e7e6af9))

### ✅ Testing

- Add PHPUnit integration suite and CI job ([12cd690](https://github.com/jrybacek/hide-a-post/commit/12cd690b1cdc23c166fdd17c4d32791450f65d54))

### 👷 CI/CD

- **quality:** Add PHP 7.4-8.5 lint, WPCS, PHPCompatibility, and PHPStan ([2ecc120](https://github.com/jrybacek/hide-a-post/commit/2ecc12068ec55b450a58cbb362e0856e2cbd8145))
- **wporg:** Add SVN deploy workflow, Plugin Check, assets, and blueprint ([4a44ca6](https://github.com/jrybacek/hide-a-post/commit/4a44ca6708b100acd7ac304eaab6db235240c180))

### 🔧 Miscellaneous Tasks

- **git:** Add gitmoji and conventional commits convention and hook ([8d65c01](https://github.com/jrybacek/hide-a-post/commit/8d65c013623f1f5f33800fb15524c9f598f27f5c))
- **changelog:** Configure git-cliff for changelog generation ([cf7cbaa](https://github.com/jrybacek/hide-a-post/commit/cf7cbaa917d171f90ba4eb6c4880ed7cd6cc4870))

### 📜 Legacy Changes

- Add correct version number to plugin ([0441233](https://github.com/jrybacek/hide-a-post/commit/0441233152c8dab3f4e832f913154b21144e5bb8))
- Excluding extra system required by WordPress ([0eff544](https://github.com/jrybacek/hide-a-post/commit/0eff544bf675032491225388e3ea6d9f6288a703))
- Adjust header to comply with WordPress standard ([7529e8d](https://github.com/jrybacek/hide-a-post/commit/7529e8d803132bbbf1c83c297445e03fcf7a45bd))

## [1.1] - 2019-12-01

### 📜 Legacy Changes

- Fix duplicate posts, improved SQL ([b41c71a](https://github.com/jrybacek/hide-a-post/commit/b41c71abe81bf790f5220380d8998ec8856add47))
- Update readme.md to make it look better in GitHub ([b8d6f7d](https://github.com/jrybacek/hide-a-post/commit/b8d6f7dab79e75612a70d74d43c656d1ab6077f6))
- Minor visual tweaks to make information easier to read ([99276f0](https://github.com/jrybacek/hide-a-post/commit/99276f0499740267a69caef3fa062fa76ab3ee12))
- Adjust location of images in readme.md ([d43b3ff](https://github.com/jrybacek/hide-a-post/commit/d43b3fffa2b4ef6faf4801035c415269ff2a533a))
- Add demo screeshots ([9cace91](https://github.com/jrybacek/hide-a-post/commit/9cace9194f33f0d59211b2c61a8d808445d8d26d))
- Update screenshot ([81a8ef2](https://github.com/jrybacek/hide-a-post/commit/81a8ef258adc41a31a211198927e611228254696))

## [1.0] - 2019-11-30

### 📜 Legacy Changes

- Initial commit ([2e21692](https://github.com/jrybacek/hide-a-post/commit/2e216925daa49774470b204d36245dd1789f4b72))
- Delete README.md ([6ad1bd9](https://github.com/jrybacek/hide-a-post/commit/6ad1bd9f8e7c1f5326465b0884c6a79e9880f39a))
- Initial commit ([9818818](https://github.com/jrybacek/hide-a-post/commit/9818818e2b63c74399cf458b12e3cfb015c5ead8))
- Rename EMA to HAP ([77297f7](https://github.com/jrybacek/hide-a-post/commit/77297f7dfb07d9b4a077a56375ee24985f923ad6))
- Add documentation ([05e69e1](https://github.com/jrybacek/hide-a-post/commit/05e69e194aa74f8ea2f4b7d3f4f33dd04c901e17))
- Remove incorrect references to pages ([1675e1b](https://github.com/jrybacek/hide-a-post/commit/1675e1bf121de93b034fcf6f00cd9e04ecc5c11b))

<!-- generated by git-cliff -->
