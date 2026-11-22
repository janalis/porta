Feature: Analysing the current file with PHPStan
  PHPStan: Analyse Current File runs the project's PHPStan on the file being
  edited. Its findings become editor diagnostics, listed in the Problems panel
  too, and a clean run clears them. The binary is the one the setting names,
  else vendor/bin/phpstan, else the PATH.

  Background:
    Given a PHP project
    And the bundled plugin "phpstan" is enabled
    And only the stand-ins are on the PATH
    And the project configuration:
      """
      {"settings": {"diagnosticMessages": "inline"}}
      """
    And a file "a.php" containing:
      """
      <?php
      $x = 1;
      $y = 2;
      """

  Scenario: An error becomes a diagnostic, and a clean run clears it
    Given an executable "vendor/bin/phpstan" doing:
      """
      $file = $argv[count($argv) - 1];
      echo json_encode(['files' => [$file => ['messages' => [['message' => 'Boom detected', 'line' => 2]]]], 'errors' => []]);
      exit(1);
      """
    And I start the app with the arguments "a.php"
    When I use the action "phpstan:analyse"
    Then I should see "Boom detected"
    When I use the action "ide_problems"
    Then I should see "✗ Boom detected"
    When I press "Escape"
    And an executable "vendor/bin/phpstan" doing:
      """
      echo json_encode(['files' => [], 'errors' => []]);
      """
    And I use the action "phpstan:analyse"
    Then I should eventually see "phpstan: no errors in a.php"
    And I should not see "Boom detected"

  Scenario: Analyse on save, switched on from the palette, analyses each save
    Given an executable "vendor/bin/phpstan" doing:
      """
      $file = $argv[count($argv) - 1];
      echo json_encode(['files' => [$file => ['messages' => [['message' => 'Saved and analysed', 'line' => 2]]]], 'errors' => []]);
      exit(1);
      """
    And I start the app with the arguments "a.php"
    When I use the action "phpstan:toggle-on-save"
    Then I should see "phpstan on save: on"
    And the project configuration should have "plugins.phpstan.autoLint" set to "true"
    When I use the action "save"
    Then I should see "Saved and analysed"

  @setting:phpstan.autoLint
  Scenario: With Analyse on save set in .porta.json, a save analyses the file
    Given the plugin setting "phpstan.autoLint" is "true"
    And an executable "vendor/bin/phpstan" doing:
      """
      $file = $argv[count($argv) - 1];
      echo json_encode(['files' => [$file => ['messages' => [['message' => 'Found on save', 'line' => 3]]]], 'errors' => []]);
      exit(1);
      """
    And I start the app with the arguments "a.php"
    When I use the action "save"
    Then I should see "Found on save"

  @setting:phpstan.binary
  Scenario: The phpstan binary setting names the executable that runs
    Given an executable "vendor/bin/phpstan" doing:
      """
      $file = $argv[count($argv) - 1];
      echo json_encode(['files' => [$file => ['messages' => [['message' => 'From vendor/bin', 'line' => 2]]]], 'errors' => []]);
      exit(1);
      """
    And an executable "tools/my-phpstan" doing:
      """
      $file = $argv[count($argv) - 1];
      echo json_encode(['files' => [$file => ['messages' => [['message' => 'From the setting', 'line' => 2]]]], 'errors' => []]);
      exit(1);
      """
    And the plugin setting "phpstan.binary" is "%workspace%/tools/my-phpstan"
    And I start the app with the arguments "a.php"
    When I use the action "phpstan:analyse"
    Then I should see "From the setting"
    And I should not see "From vendor/bin"

  Scenario: Without a phpstan anywhere, the command says where it looked
    Given I start the app with the arguments "a.php"
    When I use the action "phpstan:analyse"
    Then I should see "phpstan not found (vendor/bin or PATH)"
