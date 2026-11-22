Feature: Fixing the current file with PHP CS Fixer
  PHP CS Fixer: Fix Current File fixes the file with the project's own
  configuration and puts the result in the buffer, one undo away. Extra fix
  arguments are appended to the fix command, and Format on save fixes each
  save. The binary is the one the setting names, else
  vendor/bin/php-cs-fixer, else the PATH.

  Background:
    Given a PHP project
    And the bundled plugin "php-cs-fixer" is enabled
    And only the stand-ins are on the PATH
    And a file "a.php" containing:
      """
      <?php
      $x = 1;
      """
    And an executable "vendor/bin/php-cs-fixer" doing:
      """
      $file = $argv[count($argv) - 1];
      file_put_contents($file, file_get_contents($file).'// fixed with: '.implode(' ', array_slice($argv, 1, -1))."\n");
      """

  Scenario: Fixing puts the fixed file in the buffer, one undo away
    Given I start the app with the arguments "a.php"
    When I use the action "php-cs-fixer:fix"
    Then the editor should contain "// fixed with: fix"
    And I should see "php-cs-fixer: fixed a.php"
    When I use the action "undo"
    Then the editor should not contain "// fixed"

  @setting:php-cs-fixer.args
  Scenario: Extra fix arguments are appended to the fix command, quoted as a shell would
    Given the plugin setting "php-cs-fixer.args" is "--config='my rules.php' --dry-run"
    And I start the app with the arguments "a.php"
    When I use the action "php-cs-fixer:fix"
    Then the editor should contain "// fixed with: fix --config=my rules.php --dry-run"

  Scenario: Fix on save, switched on from the palette, fixes each save
    Given I start the app with the arguments "a.php"
    When I use the action "php-cs-fixer:toggle-on-save"
    Then I should see "php-cs-fixer on save: on"
    And the project configuration should have "plugins.php-cs-fixer.autoFormat" set to "true"
    When I use the action "save"
    Then the editor should contain "// fixed with: fix"

  @setting:php-cs-fixer.autoFormat
  Scenario: With Format on save set in .porta.json, a save fixes the file
    Given the plugin setting "php-cs-fixer.autoFormat" is "true"
    And I start the app with the arguments "a.php"
    When I use the action "save"
    Then the file "a.php" should contain "// fixed with: fix"

  @setting:php-cs-fixer.binary
  Scenario: The php-cs-fixer binary setting names the executable that runs
    Given an executable "tools/my-fixer" doing:
      """
      $file = $argv[count($argv) - 1];
      file_put_contents($file, file_get_contents($file)."// fixed by the named binary\n");
      """
    And the plugin setting "php-cs-fixer.binary" is "%workspace%/tools/my-fixer"
    And I start the app with the arguments "a.php"
    When I use the action "php-cs-fixer:fix"
    Then the editor should contain "// fixed by the named binary"
    And the editor should not contain "// fixed with"

  Scenario: Without a php-cs-fixer anywhere, the command says where it looked
    Given I delete "vendor/bin/php-cs-fixer"
    And I start the app with the arguments "a.php"
    When I use the action "php-cs-fixer:fix"
    # The toast wraps its message at "(vendor/bin or" / "PATH)"
    Then I should see "php-cs-fixer not found (vendor/bin or"
