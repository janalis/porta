Feature: Clipboard and paste history
  Ctrl+C, Ctrl+X and Ctrl+V copy, cut and paste, and also reach the system
  clipboard through OSC 52. Ctrl+Shift+V opens the clipboard history — the
  last copies, newest first — and pasting an entry makes it what Ctrl+V pastes
  next.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $alpha = 1;
      $beta = 2;
      """
    And I start the app with the arguments "a.php"

  Scenario: Copy and paste a selection
    Given I select from line 2, column 1 to line 2, column 7
    When I use the action "copy"
    Then the clipboard should contain "$alpha"
    When I put the caret at line 3, column 1
    And I use the action "paste"
    Then the editor should contain "$alpha$beta = 2;"

  Scenario: Cut removes the selection and paste puts it elsewhere
    Given I select from line 2, column 1 to line 2, column 7
    When I use the action "cut"
    Then the clipboard should contain "$alpha"
    And the editor should not contain "$alpha"
    When I put the caret at line 3, column 11
    And I use the action "paste"
    Then the editor should contain "$beta = 2;$alpha"

  Scenario: Paste an older copy from the history
    Given I select from line 2, column 1 to line 2, column 7
    And I use the action "copy"
    And I select from line 3, column 1 to line 3, column 6
    And I use the action "copy"
    And I put the caret at line 1, column 6
    When I use the action "paste_history"
    Then I should see "Paste from History"
    And I should see "$alpha" in the dialog "Paste from History"
    When I press "Down"
    And I press "Enter"
    Then the editor should contain "<?php$alpha"
    When I use the action "paste"
    Then the editor should contain "<?php$alpha$alpha"
