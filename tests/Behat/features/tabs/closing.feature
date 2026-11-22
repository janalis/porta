Feature: Closing tabs
  Ctrl+F4 or Alt+W closes the shown tab, asking first when it has unsaved
  changes. Close Other Tabs, Close All Tabs and the tab menu's Close to the
  Left and Close to the Right close in bulk, keep unsaved tabs open and say how
  many they kept. A pinned tab survives every bulk close.

  Background:
    Given a PHP project
    And a file "A.php" containing "<?php // a"
    And a file "B.php" containing "<?php // b"
    And a file "C.php" containing "<?php // c"
    And a file "D.php" containing "<?php // d"
    And I start the app with the arguments "A.php"
    And the files "B.php, C.php, D.php" are open

  Scenario: Close the shown tab
    When I use the action "close_buffer"
    Then the tabs should be "A.php, B.php, C.php"

  Scenario: Closing an unsaved tab asks first
    Given I type "x"
    When I use the action "close_buffer"
    Then I should see "Discard"
    When I type "Discard"
    And I press "Enter"
    Then the tabs should be "A.php, B.php, C.php"
    And the file "D.php" should contain exactly:
      """
      <?php // d
      """

  Scenario: Close the other tabs, keeping an unsaved one
    Given I use the action "prev_buffer"
    And I type "x"
    And I use the action "prev_buffer"
    When I use the action "close_other_tabs"
    Then the tabs should be "B.php, C.php"
    And I should see "1 unsaved tab kept open"

  Scenario: Close all tabs
    When I use the action "close_all_tabs"
    Then the tabs should be ""

  Scenario: Close the tabs to the left and to the right
    Given I use the action "prev_buffer"
    When I use the action "close_tabs_left"
    Then the tabs should be "C.php, D.php"
    When I use the action "close_tabs_right"
    Then the tabs should be "C.php"

  Scenario: A pinned tab survives a bulk close
    Given I use the action "prev_buffer"
    And I use the action "prev_buffer"
    When I use the action "toggle_pin_tab"
    Then the tab "B.php" should be pinned
    And I should see "⌾"
    When I use the action "next_buffer"
    And I use the action "close_tabs_left"
    Then the tabs should be "B.php, C.php, D.php"
    When I use the action "close_all_tabs"
    Then the tabs should be "B.php"
    When I use the action "toggle_pin_tab"
    Then the tab "B.php" should not be pinned
