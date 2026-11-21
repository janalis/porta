Feature: Merge conflicts
  F7 and Shift+F7 jump between the conflict regions git left in a file, and
  Alt+M resolves the one under the caret by keeping ours, theirs or both — a
  single undoable edit.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      <<<<<<< HEAD
      $x = 'ours';
      =======
      $x = 'theirs';
      >>>>>>> feature
      $y = 1;
      <<<<<<< HEAD
      $z = 'ours';
      =======
      $z = 'theirs';
      >>>>>>> feature
      """
    And I start the app with the arguments "a.php"

  Scenario: Walk the conflicts forwards and back
    Given the caret is at line 1, column 1
    When I use the action "next_conflict"
    Then the caret should be on line 2
    When I use the action "next_conflict"
    Then the caret should be on line 8
    When I use the action "prev_conflict"
    Then the caret should be on line 2

  Scenario: Keep theirs, then undo it in one step
    Given the caret is at line 3, column 1
    When I use the action "resolve_conflict"
    Then I should see "Resolve Conflict"
    When I type "Theirs"
    And I press "Enter"
    Then the editor text should be:
      """
      <?php
      $x = 'theirs';
      $y = 1;
      <<<<<<< HEAD
      $z = 'ours';
      =======
      $z = 'theirs';
      >>>>>>> feature
      """
    When I use the action "undo"
    Then the editor should contain "$x = 'ours';"
    And the editor should contain "$x = 'theirs';"
    And I should see "<<<<<<< HEAD" 2 times

  Scenario: Keep both sides
    Given the caret is at line 9, column 1
    When I use the action "resolve_conflict"
    And I type "Both"
    And I press "Enter"
    Then the editor text should be:
      """
      <?php
      <<<<<<< HEAD
      $x = 'ours';
      =======
      $x = 'theirs';
      >>>>>>> feature
      $y = 1;
      $z = 'ours';
      $z = 'theirs';
      """
