# PHPUnit

Runs your [PHPUnit](https://phpunit.de) tests without leaving the editor, and
shows what came of them where you are working. Bundled with Porta and off by
default — turn it on under **Settings → Plugins → phpunit**.

![A test file after a run: the failure inline, each test marked, the results in the dock](/screenshots/plugin-phpunit.svg)

Four commands, in the Plugins menu and the command palette:

- **PHPUnit: Run Test at Caret** (`Ctrl+Shift+F10`, or right-click in the
  editor) runs the test method the caret is in, every data set of it. Outside
  a test method it runs the whole file. A method is a test when its name
  starts with `test`, or when `#[Test]` or a `@test` docblock tag marks it.
- **PHPUnit: Run Tests in File** runs every test in the file — the active
  one, or the one you right-clicked in the project tree or on a tab.
- **PHPUnit: Run All Tests** runs the suite your `phpunit.xml` describes.
- **PHPUnit: Rerun Failed Tests** runs again only the tests that failed or
  errored in the last run.

A file you run is saved first, so PHPUnit tests what you see. The test run
gets a **PHPUnit** terminal tab of its own, so PHPUnit's output is there to
read in full. What it found shows up in four places:

- The **Tests** tab in the bottom dock lists each test, failures first, with
  the message. `Enter` jumps to the line a failure points at, and `F5` runs
  the same tests again.
- Each failure is a diagnostic on the assertion that failed. It shows inline,
  on the scroll stripe and in the Problems panel (`Alt+6`).
- Each test method of the file in front of you is marked `✓ passed`,
  `✗ failed` or `⊘ skipped` at the end of its line.
- The status bar counts the failing tests until a run comes back green.

Each run replaces what the last one showed, so a run of one test clears the
failures of the suite before it.

The plugin finds `phpunit` through its **phpunit binary** setting when you
set one, then `vendor/bin/phpunit`, then your `PATH`. While none of those
resolves, its menu entries stay dimmed, and its page under **Settings →
Plugins** offers to install it (`composer require --dev phpunit/phpunit`).
A test suite is the project's own code, so the plugin runs it only in a
project you trust, and not in a project opened over SSH or FTP.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| phpunit binary | empty | The executable to run; empty finds it as above |

## Keys

| Key | Action |
| --- | --- |
| `Ctrl+Shift+F10` | Run the test at the caret |
