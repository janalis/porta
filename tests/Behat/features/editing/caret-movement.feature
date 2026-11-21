Feature: Moving the caret
  The arrows, word jumps, Home and End, the ends of the document and whole
  pages move the caret without touching the text.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $alpha = beta($gamma);
      $delta = 1;
      """
    And I start the app with the arguments "a.php"

  Scenario: The arrows step one character or one line
    Given the caret is at line 2, column 1
    When I use the action "cursor_right"
    And I use the action "cursor_right"
    Then the caret should be at line 2, column 3
    When I use the action "cursor_down"
    Then the caret should be at line 3, column 3
    When I use the action "cursor_left"
    Then the caret should be at line 3, column 2
    When I use the action "cursor_up"
    Then the caret should be at line 2, column 2
    And the editor text should be:
      """
      <?php
      $alpha = beta($gamma);
      $delta = 1;
      """

  Scenario: Word jumps stop at the edges of words
    Given the caret is at line 2, column 1
    When I use the action "cursor_word_right"
    Then the caret should be at line 2, column 7
    When I use the action "cursor_word_left"
    Then the caret should be at line 2, column 1

  Scenario: Home and End go to the ends of the line
    Given the caret is at line 2, column 5
    When I use the action "cursor_line_end"
    Then the caret should be at line 2, column 23
    When I use the action "cursor_line_start"
    Then the caret should be at line 2, column 1

  Scenario: The ends of the document
    Given the caret is at line 2, column 5
    When I use the action "document_end"
    Then the caret should be at line 4, column 1
    When I use the action "document_start"
    Then the caret should be at line 1, column 1

  Scenario: Page Down and Page Up move a screen at a time
    Given a file "long.php" containing 100 numbered lines
    And I open "long.php"
    And the caret is at line 1, column 1
    When I use the action "page_down"
    And I use the action "page_down"
    Then the caret should be below line 40
    And I should see "// line 45"
    And I should not see "// line 1"
    When I use the action "page_up"
    And I use the action "page_up"
    Then the caret should be at line 1, column 1
    And I should see "// line 1"
    And I should not see "// line 45"
