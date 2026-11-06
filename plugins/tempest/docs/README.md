# Tempest

Porta's built-in syntax highlighting engine, from
[tempest/highlight](https://github.com/tempestphp/highlight). It runs in the
IDE itself and answers before the frame is drawn, so the colours keep up with
your typing. It is what **Settings → Highlighting** offers as the
built-in-quality engine. Bundled with Porta and off by default — turn it on
under **Settings → Plugins → tempest**; the welcome screen and the Highlighting
page recommend it.

![The tempest page in Settings → Plugins](/screenshots/plugin-tempest.svg)

Tempest claims every language (`*`): one it has no grammar for renders
uncoloured, not broken. A backend that knows a language better — chroma, for
instance — still wins that language, by its own claim list or by your
configuration.

Project search's comment and string filters do not depend on this plugin: they
keep working whichever highlighter is on, or none.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| Languages | `*` | Comma-separated grammar names this engine highlights, or `*` for all |
