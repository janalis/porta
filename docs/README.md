# Porta documentation

Porta is a terminal IDE for PHP projects built on
[symfony/tui](https://github.com/symfony/tui), with an LSP client, a DAP
debugger, git integration and a plugin system — all on a single
non-blocking event loop.

## Using Porta

| Guide | Contents |
| --- | --- |
| [Getting started](getting-started.md) | Installation, LSP setup, launching, first steps |
| [Editor](editor.md) | Buffers, editing, multi-caret, folding, indentation, local history, image viewer, mouse |
| [Keybindings](keybindings.md) | Every default binding, and how to rebind |
| [Search & navigation](search-navigation.md) | Go to file/symbol/test, find in file, Find in Files, problems panel |
| [Git](git.md) | Gutter markers, palette operations, the Git view (staging, commits, history) |
| [Run & terminal](run-and-terminal.md) | Run configurations, the integrated terminal |
| [Debugger](debugger.md) | DAP adapters, breakpoints, stepping, the debug panel |
| [Configuration](configuration.md) | `.porta.json`, the Settings view, `.editorconfig`, the `.porta/` state directory |
| [Plugins](plugins.md) | Installing/removing plugins, writing your own, the `PluginApi` reference |

## Language support

The UI is translated into English, French, German, Spanish, Italian,
Japanese and Hebrew — Hebrew runs fully right-to-left with a mirrored
layout. Switch under **Settings → General → Language**.

## Contributing

| Guide | Contents |
| --- | --- |
| [Architecture](architecture.md) | Event loop, DI composition root, rendering, subsystems, tests and quality gates |
| [Events](events.md) | The event bus: facts vs commands, listener priority, adding an event, the gates |
| [Troubleshooting](troubleshooting.md) | Common failures and what they mean |
