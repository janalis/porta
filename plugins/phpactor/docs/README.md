# Phpactor

Uses [Phpactor](https://phpactor.readthedocs.io) as the PHP language server
(`phpactor language-server`) instead of PHPantom. Bundled with Porta and off
by default — turn it on under **Settings → Plugins → phpactor**, and turn
phpantom off so the two do not both claim PHP. How Porta picks between language
servers is in [Getting started](../../../docs/getting-started.md#language-server).

![The phpactor page in Settings → Plugins](/screenshots/plugin-phpactor.svg)

The plugin finds `phpactor` through its **phpactor binary** setting when you
set one, then `vendor/bin/phpactor`, then your `PATH`. While none resolves,
PHP is left unserved rather than handed a broken process, and the plugin's
settings page offers to install it (`composer require --dev phpactor/phpactor`).

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| phpactor binary | empty | The executable to run; empty finds it as above |
