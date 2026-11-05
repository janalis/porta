# PHPUnit

Runs your [PHPUnit](https://phpunit.de) tests without leaving the editor, and
shows what came of them where you are working. Bundled with Porta and off by
default — turn it on under **Settings → Plugins → phpunit**.

![A test file after a run: the failure inline, each test marked, the results in the dock](/screenshots/plugin-phpunit.svg)

Six commands, in the Plugins menu and the command palette:

- **PHPUnit: Run Test at Caret** (`Ctrl+Shift+F10`, or right-click in the
  editor) runs the test method the caret is in, every data set of it. Outside
  a test method it runs the whole file. A method is a test when its name
  starts with `test`, or when `#[Test]` or a `@test` docblock tag marks it.
- **PHPUnit: Run Tests in File** runs every test in the file — the active
  one, or the one you right-clicked in the project tree or on a tab.
- **PHPUnit: Run All Tests** runs the suite your `phpunit.xml` describes.
- **PHPUnit: Rerun Failed Tests** runs again only the tests that failed or
  errored in the last run.
- **PHPUnit: Run All Tests with Coverage** runs the suite and measures which
  lines it runs — see [Coverage](#coverage).
- **PHPUnit: Hide Coverage** takes the coverage away.

A file you run is saved first, so PHPUnit tests what you see. The test run
gets a **PHPUnit** terminal tab of its own, so PHPUnit's output is there to
read in full. What it found shows up in four places:

- The **Tests** tab in the bottom dock lists each test, failures first, with
  the message. `Enter` jumps to the line a failure points at, and `F5` runs
  the same tests again.
- Each failure is a diagnostic on the assertion that failed. It shows inline,
  on the scroll stripe and in the Problems panel (`Alt+6`).
- Each test method of the file in front of you is marked `✓ test passed`,
  `✗ test failed` or `⊘ test skipped` at the end of its line; a method
  with data sets takes the worst of them.
- The status bar counts the failing tests until a run comes back green.

Each run replaces what the last one showed, so a run of one test clears the
failures of the suite before it — and so does a run that ends with no report
(stopped, or refused by its configuration). When every test passed but
PHPUnit still exits with an error — a `failOnWarning` or `failOnDeprecation`
in your `phpunit.xml`, say — you are told so rather than shown a green run:
those findings are in the terminal tab, not in the results.

## Pest

A project with [Pest](https://pestphp.com) — a `vendor/bin/pest` — runs its
tests with Pest instead, through the same commands: Pest reads PHPUnit's
options and writes the same report. **Run Test at Caret** runs the `test()` or
`it()` call the caret is in, under any `describe()` groups; outside every call
it runs the file. A failure lands on the line Pest names, and each test's mark
on its call. A **phpunit binary** you set in the settings is what runs, Pest
project or not.

## Coverage

**PHPUnit: Run All Tests with Coverage** marks, in each file the run measured,
every statement in the change strip at the left of the line numbers: a thin
green bar on a line that ran, a heavier red one on a line no test reached.
Lines that are not statements — braces, signatures — show nothing. While a
file shows coverage, the strip is coverage's: git's change marks come back
when you run **PHPUnit: Hide Coverage**.

Editing a file moves its bars with their lines, and a line you change loses
its bar. Saving the file takes its coverage away, since the run measured the
code that was there before. `F5` on the **Tests** tab measures again.

PHPUnit measures coverage with [pcov](https://github.com/krakjoe/pcov) or
[Xdebug](https://xdebug.org) in the PHP that runs it, over what `<source>` in
your `phpunit.xml` names — either will do. Xdebug collects coverage only in
its `coverage` mode, so a coverage run adds that mode for you: it starts
PHPUnit with `XDEBUG_MODE` set to `coverage`, after any mode you already set
(`debug,coverage`). pcov needs nothing. With neither, the tests still run and
you are told there is no coverage. The strip is shown while git's **Change marks in the
gutter** setting is on, as it is by default; with it off, there is nowhere to
show coverage.

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
