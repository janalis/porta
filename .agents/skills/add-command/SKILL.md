---
name: add-command
description: Add a core command or keybinding to Porta — the ActionCatalog entry, the provider, the menu, docs/keybindings.md and the seven translation catalogs, with the test that fails for each one forgotten. Use when adding a palette/menu/keyboard action to core (not a plugin).
---

Worked examples: `git show f8a2f798` (tree cut/copy/paste), `b8cf05bb`, `51c3f7ce`.

1. **`src/Config/ActionCatalog.php` `ACTIONS`**: `'my_verb' => ['label' => 'Title Case Label', 'keys' => ['ctrl+u']]`,
   with a comment on why that chord.
   - With `keys`, the action is Ide-scope, and no widget may also bind it. Without `keys`, bind it in exactly
     one widget's `getDefaultKeybindings()` or in `src/Widget/EditorKeymap.php`.
   - `adoptable: true` is only for a verb a bundled plugin implements via `adoptCommand()`.
   - Guard: `ActionCatalogTest::testEveryActionIsDefaultBoundInExactlyOnePlace`.
   - A command that is not rebindable skips the catalog and passes `label:` to `add()`. That label is
     NOT scanned by `CatalogCoverageTest`, so translate it yourself.
2. **A provider in `src/Command/*Commands.php`**: `->add('my_verb', fn () => …, keyBound: KeyDispatch::Bound)`
   (signature: `src/Config/CommandRegistry.php`).
   - Without `KeyDispatch::Bound` the chord never fires. Guard: `KeyDispatchReachabilityTest`.
   - Focus-gated: `when:` gates the key and greys the menu item. The palette ignores `when`, so the handler
     must refuse by itself, or pass `inPalette: PaletteVisibility::Hidden` (see `TreeCommands.php`).
   - A NEW provider class needs `#[AsTaggedItem(priority: N)]` and a row in
     `tests/Unit/Container/CommandProviderOrderTest.php`. Priority is both the palette order and who wins a
     chord collision. Nothing tests two Ide-scope defaults on one chord, so grep the catalog for your chord.
3. **Arch**: a new `use Porta\X` in the provider means a `'Command>X'` edge in
   `config/arch-allowlist.php`. Guard: `composer arch`.
4. **Menu**: `'i:my_verb'` (IDE command) or `'e:my_verb'` (editor action) in `MenuModel::DEFS`
   (`src/Workbench/Menu/MenuModel.php`). Also add it to `FILE_SCOPED_IDE` if it acts on the current file,
   and to `VIEW_SAFE` if it must work in Settings/Welcome.
   - Guard: `AccessLetterCoverageTest`: the item needs a free access letter in all 7 locales.
   - E2e tests that walk a menu by row index shift when an item is inserted (`IdeAppMenuTest`,
     `IdeContextMenusTest`).
5. **`docs/keybindings.md`**: a row for the chord. `KeybindingsDocTest` checks only that the CHORD appears
   somewhere, so a reused chord passes without a row. Add the row anyway.
6. **Translations**: the label is the message id. Add it to all seven catalogs in one command, which
   picks the next free id and refuses a missing locale or a source already there:
   `php bin/porta-dev i18n:add "Title Case Label" --target="fr:…" --target="de:…" --target="es:…" --target="it:…" --target="ja:…" --target="he:…"`
   - Write modifiers as `Ctrl+`, never symbols (`HintDialectTest`).
   - **A duplicate id empties the whole locale** silently. Only `CatalogCompletenessTest` catches it.
   - Guards: `CatalogCompletenessTest` (in every target, non-empty) and `CatalogCoverageTest` (used,
     and every catalog `'label' => '…'` present).

Then run the `gates` skill.
