Feature: Phpactor
  phpactor can serve PHP instead of phpantom: the plugin runs
  `phpactor language-server` from its binary setting. A stub that speaks LSP
  (the mock server) stands in for it.

  @setting:phpactor.binary
  Scenario: The binary setting names the server that serves PHP
    Given a PHP project
    And the environment variable "PATH" is "/usr/bin:/bin"
    And a stub tool "bin/phpactor" containing:
      """
      #!%php%
      <?php
      file_put_contents(getcwd().'/phpactor.args', implode(' ', array_slice($argv, 1)));
      require '%support%/mock-lsp-server.php';
      """
    And a file "src/A.php" containing:
      """
      <?php

      """
    And the bundled plugin "phpactor" is enabled
    And the "phpactor" plugin setting "binary" is the workspace path "bin/phpactor"
    And I start the app with the arguments "src/A.php"
    And I should see "LSP:phpactor"
    When I put the caret at line 2, column 1
    And I use the action "trigger_completion"
    Then the completion popup should be open
    And I should see "strlen"
    And the file "phpactor.args" should contain "language-server"

  Scenario: Without a binary, PHP is unserved and the status bar says so
    Given a PHP project
    And the environment variable "PATH" is "/usr/bin:/bin"
    And a file "src/A.php" containing "<?php"
    And the bundled plugin "phpactor" is enabled
    When I start the app with the arguments "src/A.php"
    Then I should see "LSP:phpactor not found"
