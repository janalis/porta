Feature: Context menus
  A right-click opens the menu of whatever is under the pointer: the editor,
  a tree row, a tab, a results row. Ctrl+F10 opens the focused pane's menu
  from the keyboard: at the caret in the editor, at the selected row in the
  tree.

  Background:
    Given a PHP project
    And a file "src/a.php" containing:
      """
      <?php
      echo 1;
      """
    And a file "src/b.php" containing "<?php"

  @action:show_context_menu
  Scenario: Ctrl+F10 opens the editor's menu at the caret
    When I start the app with the arguments "src/a.php"
    And I use the action "show_context_menu"
    Then I should see "Copy Reference"
    When I click on the text "Copy Path"
    Then the clipboard should contain "src/a.php"
    And I should not see "Copy Reference"

  @action:show_context_menu
  Scenario: Ctrl+F10 in the tree opens the selected row's menu
    When I start the app
    Then the element "#tree" should be focused
    When I use the action "show_context_menu"
    Then I should see "New File"
    And I should see "New Folder"
    When I press "Escape"
    Then I should not see "New Folder"

  Scenario: A right-click on a folder offers New File, which makes it there
    When I start the app with the arguments "src/a.php"
    And I right-click on the text "src/"
    And I click on the text "New File"
    And I type "c.php"
    And I press "Enter"
    Then the file "src/c.php" should exist

  Scenario: A right-click on a tab offers to close the others
    When I start the app with the arguments "src/a.php"
    And I press "Ctrl+Shift+N"
    And I type "b.php"
    And I should eventually see "▌b.php"
    And I press "Enter"
    Then the editor should show "src/b.php"
    And I should see "a.php ✖"
    When I right-click on the text "b.php ✖"
    And I click on the text "Close Others"
    Then I should not see "a.php ✖"
    And I should see "b.php ✖"

  Scenario: A right-click on a results row offers its path
    When I start the app with the arguments "src/a.php"
    And I press "Ctrl+Shift+F"
    And I type "echo"
    And I should eventually see "1 result"
    And I press "Alt+Enter"
    Then I should see "echo 1;" in "#results"
    When I right-click on the text "2  echo 1;"
    Then I should see "Copy Relative Path"
    When I click on the text "Copy Relative Path"
    Then the clipboard should contain "src/a.php"
