<!-- Registering a plugin in Porta's store: docs/plugin-store.md -->

## Plugin

- Package: `vendor/porta-name` (on Packagist)
- Repository: https://github.com/…
- What it does, in one line:

## Checklist

- [ ] The package's `composer.json` has `"type": "porta-plugin"` and its name is `vendor/porta-<name>`
- [ ] Its newest release installs and loads (`Settings › Plugins › Install plugin`, then the plugin's page)
- [ ] `extra.porta.store` names a `title` and a `category`; any `screenshots` are https PNGs of 2 MB or less
- [ ] The entry in `store/plugins.json` is the only change in this pull request
- [ ] Taking over a bundled plugin: the entry says `"replaces": "<name>"`, and I own the repository of the tool it drives

The **Store** check verifies the ownership claims; a maintainer reviews the rest.
