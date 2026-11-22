Feature: Moving through the file tree
  The project tree lists the project's folders and files. The arrows move
  the selection and fold the folders, Enter opens a file, r reloads the tree
  from the disk, and Alt+F1 finds the open file in it from anywhere.

  Background:
    Given a PHP project
    And a file "src/Kernel.php" containing:
      """
      <?php
      // the kernel
      """
    And a file "src/Service.php" containing:
      """
      <?php
      // the service
      """

  @action:move_down @action:move_up
  Scenario: The arrows move the selection
    When I start the app
    Then the element "#tree" should be focused
    And the tree should have "src" selected
    When I use the action "move_down"
    Then the tree should have "composer.json" selected
    When I use the action "move_up"
    Then the tree should have "src" selected

  @action:expand @action:collapse
  Scenario: Right unfolds a folder and Left folds it
    When I start the app
    Then I should not see "Kernel.php"
    When I use the action "expand"
    Then I should see "Kernel.php"
    And I should see "Service.php"
    When I use the action "collapse"
    Then I should not see "Kernel.php"

  @action:open
  Scenario: Enter opens the selected file, and toggles a folder
    When I start the app
    And I use the action "open"
    Then I should see "Kernel.php"
    When I press "Down"
    Then the tree should have "src/Kernel.php" selected
    When I use the action "open"
    Then the editor should show "src/Kernel.php"
    And the editor should contain "// the kernel"

  @action:reload
  Scenario: r reloads the tree from the disk
    When I start the app
    And I press "Right"
    Then I should see "Service.php"
    Given a file "src/Added.php" containing "<?php"
    When I use the action "reload"
    Then I should see "Added.php"

  @action:tree_select_opened_file
  Scenario: Alt+F1 selects the open file in the tree, from the editor
    When I start the app with the arguments "src/Service.php"
    Then I should not see "Kernel.php"
    And the element "#editor" should be focused
    When I use the action "tree_select_opened_file"
    Then the tree should have "src/Service.php" selected
    And I should see "Kernel.php"
