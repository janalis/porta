Feature: PHPantom
  phpantom_lsp is the PHP language server Porta suggests, and the same binary
  fixes files and writes a starter configuration. Here a stub stands in for it:
  with no argument it speaks LSP (the mock server), `fix` appends a marker to
  the file it is given, and `init` writes phpantom.json.

  Background:
    Given a PHP project
    And the environment variable "PATH" is "/usr/bin:/bin"
    And a stub tool "bin/phpantom_lsp" containing:
      """
      #!%php%
      <?php
      $command = $argv[1] ?? '';
      if ('fix' === $command) {
          if (isset($argv[2])) {
              file_put_contents($argv[2], file_get_contents($argv[2])."// fixed\n");
          } else {
              file_put_contents(getcwd().'/fixed-project', "1\n");
          }
          exit(0);
      }
      if ('init' === $command) {
          file_put_contents(getcwd().'/phpantom.json', "{}\n");
          exit(0);
      }
      require '%support%/mock-lsp-server.php';
      """
    And a file "src/A.php" containing:
      """
      <?php
      $x=1;
      """
    And the bundled plugin "phpantom" is enabled

  @setting:phpantom.binary
  Scenario: The binary setting names the server that serves PHP
    Given the "phpantom" plugin setting "binary" is the workspace path "bin/phpantom_lsp"
    And I start the app with the arguments "src/A.php"
    And I should see "LSP:phpantom"
    When I put the caret at line 2, column 1
    And I use the action "trigger_completion"
    Then the completion popup should be open
    And I should see "strlen"

  Scenario: Without a binary, PHP is unserved and the status bar says so
    When I start the app with the arguments "src/A.php"
    Then I should see "LSP:phpantom not found"

  Scenario: Fix Current File fixes the active file into the buffer
    Given the "phpantom" plugin setting "binary" is the workspace path "bin/phpantom_lsp"
    And I start the app with the arguments "src/A.php"
    When I use the action "phpantom:fix"
    Then the editor should contain "// fixed"
    And a toast should say "phpantom: fixed A.php"

  Scenario: Fix Project runs the fixer over the whole project
    Given the "phpantom" plugin setting "binary" is the workspace path "bin/phpantom_lsp"
    And I start the app with the arguments "src/A.php"
    When I use the action "phpantom:fix-project"
    Then the file "fixed-project" should exist
    And a toast should say "phpantom: fixed project"

  Scenario: Generate Config writes a starter configuration
    Given the "phpantom" plugin setting "binary" is the workspace path "bin/phpantom_lsp"
    And I start the app with the arguments "src/A.php"
    When I use the action "phpantom:init"
    Then the file "phpantom.json" should exist
    And a toast should say "phpantom: generated config"

  @setting:phpantom.autoFix
  Scenario: Fix on save fixes a PHP file when it is saved
    Given the "phpantom" plugin setting "binary" is the workspace path "bin/phpantom_lsp"
    And the project configuration:
      """
      {"plugins": {"phpantom": {"autoFix": true}}}
      """
    And I start the app with the arguments "src/A.php"
    When I type "// edited"
    And I press "Ctrl+S"
    Then the file "src/A.php" should contain "// fixed"

  Scenario: Toggle Fix on Save turns fixing on save on, and the project remembers
    Given the "phpantom" plugin setting "binary" is the workspace path "bin/phpantom_lsp"
    And I start the app with the arguments "src/A.php"
    When I use the action "phpantom:toggle-auto-fix"
    Then the "phpantom" plugin setting "autoFix" should be "true"
    When I type "// edited"
    And I press "Ctrl+S"
    Then the file "src/A.php" should contain "// fixed"
