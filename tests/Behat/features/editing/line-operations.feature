Feature: Deleting, duplicating and moving lines
  The line operations work on the caret's line, or on every line a selection
  touches: delete, duplicate, move, sort, join and flip the case — each one
  step of undo.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $charlie = 3;
      $alpha = 1;
      $bravo = 2;
      """
    And I start the app with the arguments "a.php"

  Scenario: Backspace and Delete remove one character either side of the caret
    Given the caret is at line 2, column 3
    When I use the action "delete_char_backward"
    Then the editor should contain "$harlie = 3;"
    When I use the action "delete_char_forward"
    Then the editor should contain "$arlie = 3;"
    And the caret should be at line 2, column 2

  Scenario: Delete the word before the caret, or the rest of the line
    Given the caret is at line 2, column 9
    When I use the action "delete_word_backward"
    Then the editor text should be:
      """
      <?php
       = 3;
      $alpha = 1;
      $bravo = 2;
      """
    When I use the action "delete_to_line_end"
    Then the editor text should be:
      """
      <?php

      $alpha = 1;
      $bravo = 2;
      """

  Scenario: Delete and duplicate the caret's line
    Given the caret is at line 3, column 1
    When I use the action "duplicate_line"
    Then the editor text should be:
      """
      <?php
      $charlie = 3;
      $alpha = 1;
      $alpha = 1;
      $bravo = 2;
      """
    And the caret should be on line 4
    When I use the action "delete_line"
    And I use the action "delete_line"
    Then the editor text should be:
      """
      <?php
      $charlie = 3;
      $alpha = 1;
      """

  Scenario: Move the caret's line up and down
    Given the caret is at line 2, column 1
    When I use the action "move_line_down"
    Then the editor text should be:
      """
      <?php
      $alpha = 1;
      $charlie = 3;
      $bravo = 2;
      """
    And the caret should be on line 3
    When I use the action "move_line_up"
    Then the editor text should be:
      """
      <?php
      $charlie = 3;
      $alpha = 1;
      $bravo = 2;
      """

  Scenario: Sort the selected lines
    Given I select from line 2, column 1 to line 4, column 12
    When I use the action "sort_lines"
    Then the editor text should be:
      """
      <?php
      $alpha = 1;
      $bravo = 2;
      $charlie = 3;
      """

  Scenario: Join the caret's line with the next one
    Given the caret is at line 3, column 1
    When I use the action "join_lines"
    Then the editor text should be:
      """
      <?php
      $charlie = 3;
      $alpha = 1; $bravo = 2;
      """

  Scenario: Flip the case of the caret's line, and back
    Given the caret is at line 3, column 1
    When I use the action "toggle_case"
    Then the editor should contain "$ALPHA = 1;"
    When I use the action "toggle_case"
    Then the editor should contain "$alpha = 1;"

  Scenario: A statement hops the blank lines to sit next to its neighbour
    Given a file "b.php" containing:
      """
      <?php
      $first = 1;


      $second = 2;
      """
    And I open "b.php"
    And the caret is at line 5, column 1
    When I use the action "move_statement_up"
    Then the editor text should be:
      """
      <?php
      $first = 1;
      $second = 2;
      """
    When I use the action "move_statement_up"
    Then the editor text should be:
      """
      <?php
      $second = 2;
      $first = 1;
      """
    When I use the action "move_statement_down"
    Then the editor text should be:
      """
      <?php
      $first = 1;
      $second = 2;
      """
