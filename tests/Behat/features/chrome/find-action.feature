Feature: Find Action and Search Everywhere
  Find Action (Ctrl+Shift+A) runs any command by its name, and shows the key
  that would have run it. Search Everywhere (Ctrl+Shift+E) finds files and
  symbols in one list.

  Background:
    Given a PHP project
    And a file "src/Kernel.php" containing:
      """
      <?php
      $x = 1;
      """
    And a file "src/Target.php" containing:
      """
      <?php
      final class Target {}
      """

  @action:ide_command_palette
  Scenario: Find Action runs a command by its name and shows its key
    When I start the app with the arguments "src/Kernel.php"
    And I press "Ctrl+Shift+A"
    Then I should see "Find Action"
    When I type "cycle line"
    Then I should see "Cycle Line Numbers"
    And I should see "Ctrl+Shift+L"
    When I press "Enter"
    Then I should not see "Find Action"
    And the file ".porta.json" should contain "relative"

  Scenario: Escape closes Find Action and runs nothing
    When I start the app with the arguments "src/Kernel.php"
    And I press "Ctrl+Shift+A"
    And I type "cycle line"
    And I press "Escape"
    Then I should not see "Find Action"
    And the file ".porta.json" should not exist
    And the element "#editor" should be focused

  @action:ide_search_everywhere
  Scenario: Search Everywhere finds a file and opens it
    When I start the app with the arguments "src/Kernel.php"
    And I press "Ctrl+Shift+E"
    Then I should see "Search Everywhere"
    When I type "Target"
    Then I should eventually see "Target.php"
    When I press "Enter"
    Then the editor should show "src/Target.php"
    And the editor should contain "final class Target"
