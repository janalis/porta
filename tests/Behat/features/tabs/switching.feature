Feature: Switching between open files
  Every open file has a tab. Ctrl+Left and Ctrl+Right step through them,
  Ctrl+Shift+Left and Ctrl+Shift+Right move the shown tab along the strip, and
  Ctrl+E lists the open files and then the ones closed before, newest first.
  Each buffer keeps its own caret.

  Background:
    Given a PHP project
    And a file "A.php" containing "<?php // a"
    And a file "B.php" containing "<?php // b"
    And a file "C.php" containing "<?php // c"
    And I start the app with the arguments "A.php"
    And the files "B.php, C.php" are open

  Scenario: Step to the previous and the next tab
    Then the tabs should be "A.php, B.php, C.php"
    And the editor should show "C.php"
    When I use the action "prev_buffer"
    Then the editor should show "B.php"
    When I use the action "prev_buffer"
    Then the editor should show "A.php"
    When I use the action "next_buffer"
    Then the editor should show "B.php"

  Scenario: Each buffer keeps its caret
    Given the caret is at line 1, column 7
    When I use the action "prev_buffer"
    And the caret is at line 1, column 3
    And I use the action "next_buffer"
    Then the editor should show "C.php"
    And the caret should be at line 1, column 7

  Scenario: Move the shown tab left and right
    When I use the action "move_tab_left"
    Then the tabs should be "A.php, C.php, B.php"
    When I use the action "move_tab_left"
    And I use the action "move_tab_left"
    Then the tabs should be "C.php, A.php, B.php"
    When I use the action "move_tab_right"
    Then the tabs should be "A.php, C.php, B.php"
    And the editor should show "C.php"

  Scenario: Recent files lists the open tabs and the closed ones
    When I use the action "close_buffer"
    Then the tabs should be "A.php, B.php"
    When I use the action "recent_files"
    Then I should see "C.php" in the dialog "Recent Files"
    When I type "C.php"
    And I press "Enter"
    Then the editor should show "C.php"
    And the tabs should be "A.php, B.php, C.php"
