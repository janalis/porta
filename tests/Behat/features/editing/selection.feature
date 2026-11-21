Feature: Selecting text
  Shift with any caret movement extends a selection from where the caret
  started; Select All takes the whole buffer, and expand / shrink selection
  climb the syntax around the caret.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $x = foo(bar, baz);
      $y = 2;
      """
    And I start the app with the arguments "a.php"

  Scenario: Shift and the arrows extend the selection by characters and lines
    Given the caret is at line 2, column 1
    When I use the action "select_right"
    And I use the action "select_right"
    Then the selection should be "$x"
    When I use the action "select_left"
    Then the selection should be "$"
    When I use the action "select_down"
    Then the selection should be "$x = foo(bar, baz);\n$"
    When I use the action "select_up"
    Then the selection should be "$"

  Scenario: Shift with the word keys extends the selection by words
    Given the caret is at line 2, column 6
    When I use the action "select_word_right"
    Then the selection should be "foo"
    When I use the action "select_word_left"
    Then nothing should be selected
    When I use the action "select_word_left"
    Then the selection should be "= "

  Scenario: Shift with Home and End selects to the ends of the line
    Given the caret is at line 2, column 6
    When I use the action "select_line_end"
    Then the selection should be "foo(bar, baz);"
    When I use the action "select_line_start"
    Then the selection should be "$x = "

  Scenario: Select All takes the whole buffer, and typing replaces it
    When I use the action "select_all"
    Then the selection should be "<?php\n$x = foo(bar, baz);\n$y = 2;\n"
    When I type "gone"
    Then the editor text should be:
      """
      gone
      """

  Scenario: Expand and shrink the selection through the syntax
    Given the caret is at line 2, column 11
    When I use the action "expand_selection"
    Then the selection should be "bar"
    When I use the action "expand_selection"
    Then the selection should be "bar, baz"
    When I use the action "expand_selection"
    Then the selection should be "(bar, baz)"
    When I use the action "shrink_selection"
    And I use the action "shrink_selection"
    Then the selection should be "bar"
