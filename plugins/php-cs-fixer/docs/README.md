# PHP CS Fixer

Runs [PHP CS Fixer](https://cs.symfony.com) on the file you are editing.
Bundled with Porta and off by default — turn it on under **Settings →
Plugins → php-cs-fixer**.

![The php-cs-fixer page in Settings → Plugins](/screenshots/plugin-php-cs-fixer.svg)

**PHP CS Fixer: Fix Current File** (`Ctrl+Alt+F`, or the Plugins menu) fixes
the file with your project's own configuration and applies the result to the
open buffer: the change lands the moment the fixer exits, works on unsaved
edits, and is one `Ctrl+Z` away.

The plugin finds `php-cs-fixer` through its **php-cs-fixer binary** setting when you
set one, then `vendor/bin/php-cs-fixer`, then your `PATH`. While none of those
resolves, its menu entries stay dimmed, and its page under **Settings →
Plugins** offers to install it (`composer require --dev friendsofphp/php-cs-fixer`).

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| php-cs-fixer binary | empty | The executable to run; empty finds it as above |
| Extra fix arguments | empty | Appended to the `fix` command, e.g. `--config=.php-cs-fixer.dist.php` |
| Format on save | off | Fix each PHP file when you save it (**PHP CS Fixer: Toggle Fix on Save**) |

## Keys

| Key | Action |
| --- | --- |
| `Ctrl+Alt+F` | Fix the current file |
