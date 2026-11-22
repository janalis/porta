Feature: The bottom dock
  The results panels share the dock at the bottom of the screen, one tab
  each. Alt+PageDown and Alt+PageUp step through the tabs, Ctrl+Shift+F4
  closes the one on display, and Structure (Alt+7) lists the open file's
  classes and members.

  Background:
    Given a PHP project
    And the mock language server is configured
    And a file "src/Demo.php" containing:
      """
      <?php

      class Demo
      {
        public function greet() {}
      }
      """

  @action:ide_structure
  Scenario: Structure lists the open file's symbols
    When I start the app with the arguments "src/Demo.php"
    And I should eventually see "LSP:mock-lsp-server"
    And I use the action "ide_structure"
    Then I should eventually see "Structure"
    And I should see "greet" in "#results"
    And I should see "Demo" in "#results"

  @action:dock_next_panel @action:dock_prev_panel @action:dock_close_tab
  Scenario: The dock's tabs are stepped through and closed
    When I start the app with the arguments "src/Demo.php"
    And I should eventually see "LSP:mock-lsp-server"
    And I use the action "ide_structure"
    And I should see "greet" in "#results"
    And I press "Alt+6"
    Then I should see "Problems" in "#dock-tabs"
    And I should see "Structure" in "#dock-tabs"
    And I should not see "greet" in "#results"
    When I use the action "dock_prev_panel"
    Then I should see "greet" in "#results"
    When I use the action "dock_next_panel"
    Then I should not see "greet" in "#results"
    When I use the action "dock_prev_panel"
    And I use the action "dock_close_tab"
    Then I should not see "Structure" in "#dock-tabs"
    And I should see "Problems" in "#dock-tabs"
