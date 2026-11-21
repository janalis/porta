Feature: Find in Files
  Ctrl+Shift+F searches the whole project and previews the matches, grouped by
  file, as you type. A scope narrows where it looks, a mask which files, and a
  replacement rewrites every match it keeps.

  Background:
    Given a PHP project
    And a file "src/A.php" containing:
      """
      <?php
      // tok here
      $foo = 1;
      """
    And a file "src/C.php" containing:
      """
      <?php
      // tok here
      """
    And a file "other/B.php" containing:
      """
      <?php
      // tok here
      $foo = 2;
      """
    And a file "other/D.js" containing "// tok here"
    And I start the app with the arguments "src/A.php"

  Scenario: The matches preview as you type, grouped by file
    When I use the action "ide_find_in_project"
    And I type "tok"
    Then I should see "src/A.php"
    And I should see "src/C.php"
    And I should see "other/B.php"
    And I should see "other/D.js"

  Scenario: The Directory scope keeps to the current file's directory
    When I use the action "ide_find_in_project"
    And I type "tok"
    And I should see "other/B.php"
    And I press "Alt+s"
    And I press "Down"
    And I press "Enter"
    Then I should see "Directory"
    And I should see "src/C.php"
    And I should not see "other/B.php"

  Scenario: The Current File scope keeps to the file being edited
    When I use the action "ide_find_in_project"
    And I type "tok"
    And I should see "src/C.php"
    And I press "Alt+s"
    And I press "Down" 2 times
    And I press "Enter"
    Then I should see "Current File"
    And I should see "src/A.php"
    And I should not see "src/C.php"

  Scenario: A file mask keeps to the files it matches
    When I use the action "ide_find_in_project"
    And I type "tok"
    And I should see "other/D.js"
    And I press "Tab"
    And I press "Tab"
    And I type "*.php"
    Then I should see "other/B.php"
    And I should not see "other/D.js"

  Scenario: Replace all rewrites the open buffer and the files on disk
    When I use the action "ide_find_in_project"
    And I type "foo"
    And I should see "$foo = 2;"
    And I press "Tab"
    And I type "bar"
    And I should see "→"
    And I press "Alt+a"
    Then the editor should contain "$bar = 1;"
    And the file "other/B.php" should contain "$bar = 2;"

  Scenario: Enter opens the selected match
    When I use the action "ide_find_in_project"
    And I type "$foo = 2"
    And I should see "other/B.php"
    And I press "Enter"
    Then the editor should show "other/B.php"
    And the caret should be on line 3
