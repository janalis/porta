Feature: The status bar and the navigation bar
  The status bar's cells are clickable: the caret position opens Go to Line,
  the file's path leads back to the tree, and a cell with a menu, such as the
  git branch, drops it. Alt+End gathers those menus in one; Alt+Home is the
  breadcrumb trail above the editor, as a menu.

  Background:
    Given a PHP project
    And a file "src/a.php" containing:
      """
      <?php
      echo 1;
      """

  Scenario: A click on the caret position opens Go to Line
    When I start the app with the arguments "src/a.php"
    And I click on the text "1:1"
    Then I should eventually see "Go to line"

  @action:tree_select_opened_file
  Scenario: A right-click on the file's path offers to show it in the tree
    When I start the app with the arguments "src/a.php"
    Then I should not see "a.php" in "#tree"
    When I right-click on the text "src/a.php"
    Then I should see "Select Opened File in Tree"
    And I should see "Copy Relative Path"
    When I click on the text "Select Opened File in Tree"
    Then the tree should have "src/a.php" selected
    And I should see "a.php" in "#tree"

  @action:ide_status_menus
  Scenario: Alt+End gathers the status bar's menus
    Given a git repository with everything committed
    And the bundled plugin "git" is enabled
    When I start the app with the arguments "src/a.php"
    And I should eventually see "main ▾"
    And I use the action "ide_status_menus"
    Then I should eventually see "New Branch"

  Scenario: With no cell offering a menu, Alt+End says so
    When I start the app with the arguments "src/a.php"
    And I use the action "ide_status_menus"
    Then I should eventually see "No context menu here"

  @action:ide_navigation_bar
  Scenario: Alt+Home walks the breadcrumb trail, and a folder is shown in the tree
    When I start the app with the arguments "src/a.php"
    Then I should see "src › a.php"
    When I use the action "ide_navigation_bar"
    Then I should see "┃ src"
    And I should see "┃ a.php"
    When I press "Enter"
    Then the tree should have "src" selected
