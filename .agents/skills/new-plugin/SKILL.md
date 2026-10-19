---
name: new-plugin
description: Add a bundled plugin under plugins/<name>/ in the Porta repo — layout, autoload, the manual mago.toml and arch-allowlist entries, its own 100% coverage, its translations and docs. Use when creating a new bundled plugin (for the public plugin API itself, read docs/plugins.md).
---

Worked examples: `git show --stat b4506a85` (mcp), `006b7600` (symfony-lsp). The API is
`docs/plugins.md`; loading, ordering and the drain are `docs/internals/plugin-system.md`.

1. **`plugins/<name>/plugin.php`**: `return new class('<name>') extends AbstractPlugin { … };`.
   It only wires; code lives in `plugins/<name>/src/`. Use anonymous classes in plugin files (a
   cross-plugin redeclaration is an uncatchable fatal), and a plugin class is never a container service.
2. **`plugins/<name>/composer.json`**: `"type": "porta-plugin"`, `autoload.psr-4`
   `"Porta\\<Ns>\\": "src/"`, optional `extra.porta.requires` / `api` / `commands`.
   - Autoload is automatic (`PluginAutoloader`; tests get `<Ns>Tests\` via `tests/plugin-autoload.php`).
   - Never edit the root `composer.json` for a plugin.
3. **Discovery is automatic** (`PluginLoader::embeddedDir()`), but the plugin is OFF unless enabled. To
   make it default-on, add it to `IdeConfig::DEFAULT_ENABLED_PLUGINS`. Tests run with
   `PORTA_EMBEDDED_PLUGINS=/nonexistent`: a plugin test opts in with `use UsesBundledPlugins` plus
   `enabledPlugins` in its `.porta.json` (see `plugins/symfony-lsp/tests/IdeSymfonyLspPluginTest.php`).
4. **`plugins/<name>/tests/`** joins the Plugins suite and `coverage:plugins` automatically (one shard per
   `*Test.php`).
   - **The plugin's OWN tests must reach 100%** of its `src/` AND its `plugin.php` (`--require=plugins`).
     Core tests don't count.
5. **`mago.toml`**: add `"plugins/<name>/tests"` to the analyzer `excludes` by hand; mago excludes take no
   globs. If the plugin vendors dependencies, also add a `plugins/<name>/vendor` source exclude
   (`BundledPluginVendorTest`).
6. **Arch**: in `ALLOWED_EDGES` (`src/Console/ArchitectureCheckCommand.php`), add one commented
   `'<Ns>>X'` edge per core namespace the plugin's `src/` imports. `<Ns>` is the psr-4 segment after
   `Porta\`, not the directory name (`GitPlugin`, but `SymfonyLsp`). `plugin.php` is outside the graph.
   Guard: `composer arch` (fails on drift in both directions).
7. **Translations**, if it has strings: `plugins/<name>/translations/messages.{en,de,es,fr,he,it,ja}.xlf`,
   ids from 1, called through `$api->trans()`; add each string with
   `php bin/porta-dev i18n:add "…" --plugin=<name> --target=fr:… …`. The catalog gates pick it up automatically.
   - All 7 files are required.
   - A source string may not be in two sets.
   - Every unit must be used by this plugin.
   - A core unit only this plugin uses must move here (`CatalogCoverageTest::testNoCoreUnitServesASinglePlugin`).
8. **Docs**:
   - `plugins/<name>/docs/README.md` — what it does, a `## Settings` table, a `## Keys` table for
     every chord it binds (`DocsIndexTest::testEveryBundledPluginShipsItsOwnGuide`,
     `KeybindingsDocTest` reads a plugin's keys from ITS docs, not `docs/keybindings.md`).
   - A row linking it in `docs/README.md`'s **Bundled plugins** table (`DocsIndexTest`).
   - A row per new `PluginApi` method (`PluginApiDocTest`).
   - New test support in `docs/internals/testing.md`.
   - `src/Console/PortaCommand.php` if the CLI help names it.

Then run the `gates` skill; `composer test:plugins` covers the new suite.
