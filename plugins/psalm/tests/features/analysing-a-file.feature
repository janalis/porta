Feature: Analysing the current file with Psalm
  Psalm: Analyse Current File runs the project's Psalm on the file being
  edited. Its findings become editor diagnostics, and a clean run clears them.
  Analyse on save runs it on each save. The binary is the one the setting
  names, else vendor/bin/psalm, else the PATH.

  Background:
    Given a PHP project
    And the bundled plugin "psalm" is enabled
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

  Scenario: An issue becomes a diagnostic, and a clean run clears it
    Given an executable "vendor/bin/psalm" doing:
      """
      echo json_encode([['severity' => 'error', 'type' => 'UndefinedVariable', 'message' => 'Boom detected', 'line_from' => 2, 'column_from' => 1]]);
      exit(2);
      """
    And I start the app with the arguments "a.php"
    When I use the action "psalm:analyse"
    Then I should see "Boom detected"
    When I use the action "ide_problems"
    Then I should see "✗ Boom detected"
    When I press "Escape"
    And an executable "vendor/bin/psalm" doing:
      """
      echo json_encode([]);
      """
    And I use the action "psalm:analyse"
    Then I should eventually see "psalm: no issues in a.php"
    And I should not see "Boom detected"

  Scenario: Analyse on save, switched on from the palette, analyses each save
    Given an executable "vendor/bin/psalm" doing:
      """
      echo json_encode([['severity' => 'error', 'type' => 'X', 'message' => 'Saved and analysed', 'line_from' => 2, 'column_from' => 1]]);
      exit(2);
      """
    And I start the app with the arguments "a.php"
    When I use the action "psalm:toggle-on-save"
    Then I should see "psalm on save: on"
    And the project configuration should have "plugins.psalm.autoLint" set to "true"
    When I use the action "save"
    Then I should see "Saved and analysed"

  @setting:psalm.autoLint
  Scenario: With Analyse on save set in .porta.json, a save analyses the file
    Given the plugin setting "psalm.autoLint" is "true"
    And an executable "vendor/bin/psalm" doing:
      """
      echo json_encode([['severity' => 'error', 'type' => 'X', 'message' => 'Found on save', 'line_from' => 3, 'column_from' => 1]]);
      exit(2);
      """
    And I start the app with the arguments "a.php"
    When I use the action "save"
    Then I should see "Found on save"

  @setting:psalm.binary
  Scenario: The psalm binary setting names the executable that runs
    Given an executable "vendor/bin/psalm" doing:
      """
      echo json_encode([['severity' => 'error', 'type' => 'X', 'message' => 'From vendor/bin', 'line_from' => 2, 'column_from' => 1]]);
      exit(2);
      """
    And an executable "tools/my-psalm" doing:
      """
      echo json_encode([['severity' => 'error', 'type' => 'X', 'message' => 'From the setting', 'line_from' => 2, 'column_from' => 1]]);
      exit(2);
      """
    And the plugin setting "psalm.binary" is "%workspace%/tools/my-psalm"
    And I start the app with the arguments "a.php"
    When I use the action "psalm:analyse"
    Then I should see "From the setting"
    And I should not see "From vendor/bin"

  Scenario: Without a psalm anywhere, the command says where it looked
    Given I start the app with the arguments "a.php"
    When I use the action "psalm:analyse"
    Then I should see "psalm not found (vendor/bin or PATH)"
