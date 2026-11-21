Feature: Opening a project

  Scenario: The IDE shows the project's files and the menu bar
    Given a PHP project
    And a file "src/Kernel.php" containing:
      """
      <?php
      echo 1;
      """
    When I start the app with the arguments "src/Kernel.php"
    Then I should see "Kernel.php"
    And the editor should contain "echo 1;"

  Scenario: Find Action runs an action by its label
    Given a PHP project
    And a file "a.php" containing "<?php"
    When I start the app with the arguments "a.php"
    And I use the action "ide_settings"
    Then I should eventually see "Settings"
