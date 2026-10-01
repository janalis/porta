# Psalm

Runs [Psalm](https://psalm.dev) on the file you are editing. Bundled with
Porta and off by default — turn it on under **Settings → Plugins → psalm**.

**Psalm: Analyse Current File** (`Ctrl+Alt+P`, or the Plugins menu) analyses
the active file with your project's own Psalm configuration. Findings are published as editor diagnostics — inline messages, gutter and
stripe marks, and the Problems panel (`Alt+6`), exactly like a language
server's — and a clean run clears them. A language server that publishes
diagnostics for the same file replaces them, and the other way round: the last
writer wins.

The plugin finds `psalm` through its **psalm binary** setting when you
set one, then `vendor/bin/psalm`, then your `PATH`. While none of those
resolves, its menu entries stay dimmed, and its page under **Settings →
Plugins** offers to install it (`composer require --dev vimeo/psalm`).

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| psalm binary | empty | The executable to run; empty finds it as above |
| Analyse on save | off | Analyse each PHP file when you save it. **Psalm: Toggle Analyse on Save** flips it from the palette |

## Keys

| Key | Action |
| --- | --- |
| `Ctrl+Alt+P` | Analyse the current file |
