# Xdebug

Debugs PHP with [Xdebug](https://xdebug.org), set up in one step. Bundled with
Porta and off by default — turn it on under **Settings → Plugins → xdebug**.

![The xdebug page in Settings → Plugins](/screenshots/plugin-xdebug.svg)

Porta's debugger speaks the Debug Adapter Protocol, and for PHP the adapter is
[vscode-php-debug](https://github.com/xdebug/vscode-php-debug), which runs
with [Node.js](https://nodejs.org). This plugin supplies it, so **Shift+F9**
debugs the PHP file in front with nothing to configure — see
[the debugger](../../../docs/debugger.md) for breakpoints, stepping and the
debug panel. Your PHP needs the Xdebug extension; the adapter starts PHP with
Xdebug's debug mode switched on for that run.

The adapter is looked for in this order:

1. the **php-debug adapter script** setting, when you set one;
2. the copy this plugin installed;
3. the php-debug extension VS Code, VS Code Insiders, Cursor, VSCodium or
   Windsurf already installed — the newest of them.

While none is found, the plugin's Settings page offers **Install**: it
downloads php-debug 1.40.2 from its GitHub release and keeps it under
`~/.porta/tools/php-debug`. That release publishes no checksum, so the plugin
carries one: a download whose SHA-256 is not the release's is thrown away. A
newer adapter arrives with a newer Porta. The button stays once something is
found — pressing it again installs the pinned release over the plugin's own
copy, which is how to repair it.

An adapter you name in `.porta.json` under `languages.php.debug` still wins
over this plugin's.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| php-debug adapter script | empty | The adapter's `phpDebug.js`; empty finds it as above |
| node binary | `node` | What runs the adapter |

Both name a program the debugger runs, so a project you have not trusted
cannot set them in its own `.porta.json`: only your own configuration can.
