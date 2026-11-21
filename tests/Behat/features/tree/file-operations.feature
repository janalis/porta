Feature: Changing files from the tree
  With the tree focused, the file manager's keys act on the selected rows:
  New… (Alt+Insert or Ctrl+N), Rename… (Shift+F6), Delete after a y/N
  question, and Ctrl+X, Ctrl+C and Ctrl+V to move and copy files.

  Background:
    Given a PHP project
    And a file "src/a.php" containing:
      """
      <?php
      // a
      """
    And a directory "lib"

  @action:tree_new
  Scenario: New… makes a file in the selected folder
    When I start the app
    And I use the action "tree_new"
    Then I should eventually see "New"
    When I type "File"
    And I press "Enter"
    And I type "made.php"
    And I press "Enter"
    Then the file "lib/made.php" should exist

  @action:tree_rename
  Scenario: Rename… renames the selected file on the disk and in its tab
    When I start the app with the arguments "src/a.php"
    And I use the action "tree_select_opened_file"
    And I use the action "ide_project_tree"
    And I use the action "tree_rename"
    Then I should eventually see "Rename"
    And I press "Backspace" 5 times
    And I type "b.php"
    And I press "Enter"
    Then the file "src/b.php" should exist
    And the file "src/a.php" should not exist
    And the editor should show "src/b.php"

  @action:tree_delete
  Scenario: Delete asks first, and y deletes the file
    When I start the app with the arguments "src/a.php"
    And I use the action "tree_select_opened_file"
    And I use the action "ide_project_tree"
    And I use the action "tree_delete"
    Then I should eventually see "Delete a.php? (y/N)"
    When I type "n"
    And I press "Enter"
    Then I should not see "Delete a.php?"
    And the file "src/a.php" should exist
    When I use the action "ide_project_tree"
    And I use the action "tree_delete"
    And I should eventually see "Delete a.php? (y/N)"
    And I type "y"
    And I press "Enter"
    Then the file "src/a.php" should not exist

  @action:tree_cut @action:tree_paste
  Scenario: Cut and paste move a file to another folder
    When I start the app with the arguments "src/a.php"
    And I use the action "tree_select_opened_file"
    And I use the action "ide_project_tree"
    And I use the action "tree_cut"
    And I press "Up"
    And I press "Up"
    Then the tree should have "lib" selected
    When I use the action "tree_paste"
    Then the file "lib/a.php" should exist
    And the file "src/a.php" should not exist

  @action:tree_copy
  Scenario: Copy and paste leave the original in place
    When I start the app with the arguments "src/a.php"
    And I use the action "tree_select_opened_file"
    And I use the action "ide_project_tree"
    And I use the action "tree_copy"
    And I press "Up"
    And I press "Up"
    And I use the action "tree_paste"
    Then the file "lib/a.php" should exist
    And the file "src/a.php" should exist
