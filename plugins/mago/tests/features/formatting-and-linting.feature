Feature: Formatting and linting with Mago
  Mago: Format Current File rewrites the file and puts the result in the
  buffer, one undo away; Mago: Lint Current File publishes its findings as
  editor diagnostics. Format on save and Lint on save run them on each save,
  formatting first. The binary is the one the setting names, else
  vendor/bin/mago, else the PATH.

  Background:
    Given a PHP project
    And the bundled plugin "mago" is enabled
    And only the stand-ins are on the PATH
    And the project configuration:
      """
      {"settings": {"diagnosticMessages": "inline"}}
      """
    And a file "a.php" containing:
      """
      <?php
      $x = 1;
      """
    And an executable "vendor/bin/mago" doing:
      """
      $file = $argv[count($argv) - 1];
      if ('format' === $argv[1]) {
          file_put_contents($file, file_get_contents($file)."// formatted\n");
          exit(0);
      }
      echo json_encode(['issues' => [[
          'level' => 'Error',
          'code' => 'test-rule',
          'message' => 'Bad thing',
          'annotations' => [['kind' => 'Primary', 'span' => ['start' => ['offset' => 7, 'line' => 1]]]],
      ]]]);
      """

  Scenario: Formatting puts the formatted file in the buffer, one undo away
    Given I start the app with the arguments "a.php"
    When I use the action "mago:format"
    Then the editor should contain "// formatted"
    And the file "a.php" should contain "// formatted"
    When I use the action "undo"
    Then the editor should not contain "// formatted"

  Scenario: Linting publishes the issues as diagnostics
    Given I start the app with the arguments "a.php"
    When I use the action "mago:lint"
    Then I should see "Bad thing"
    And I should see "mago: 1 issue in a.php"

  Scenario: Format on save and Lint on save, switched on from the palette, format then lint each save
    Given I start the app with the arguments "a.php"
    When I use the action "mago:toggle-auto-format"
    Then I should see "mago format on save: on"
    When I use the action "mago:toggle-auto-lint"
    Then I should see "mago lint on save: on"
    And the project configuration should have "plugins.mago.autoFormat" set to "true"
    And the project configuration should have "plugins.mago.autoLint" set to "true"
    When I use the action "save"
    Then the file "a.php" should contain "// formatted"
    And I should see "Bad thing"

  @setting:mago.autoFormat
  Scenario: With Format on save set in .porta.json, a save formats the file and lints nothing
    Given the plugin setting "mago.autoFormat" is "true"
    And I start the app with the arguments "a.php"
    When I use the action "save"
    Then the editor should contain "// formatted"
    And I should not see "Bad thing"

  @setting:mago.autoLint
  Scenario: With Lint on save set in .porta.json, a save lints the file and leaves it as it is
    Given the plugin setting "mago.autoLint" is "true"
    And I start the app with the arguments "a.php"
    When I use the action "save"
    Then I should see "Bad thing"
    And the file "a.php" should not contain "// formatted"

  @setting:mago.binary
  Scenario: The mago binary setting names the executable that runs
    Given an executable "tools/my-mago" doing:
      """
      echo json_encode(['issues' => [['level' => 'Warning', 'code' => 'r', 'message' => 'From the setting', 'annotations' => []]]]);
      """
    And the plugin setting "mago.binary" is "%workspace%/tools/my-mago"
    And I start the app with the arguments "a.php"
    When I use the action "mago:lint"
    Then I should see "From the setting"
    And I should not see "Bad thing"
