Feature: Commenting code
  Ctrl+/ comments the line, or every selected line, with the language's line
  comment; Ctrl+Shift+/ puts one block comment around exactly what is
  selected. Either comes off again the way it went on.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      foo(bar, baz);
      $y = 2;
      """
    And I start the app with the arguments "a.php"

  Scenario: A line comment goes on and comes off the caret's line
    Given the caret is at line 2, column 3
    When I use the action "toggle_comment"
    Then the editor should contain "// foo(bar, baz);"
    When I use the action "toggle_comment"
    Then the editor text should be:
      """
      <?php
      foo(bar, baz);
      $y = 2;
      """

  Scenario: Every selected line is commented
    Given I select from line 2, column 1 to line 3, column 3
    When I use the action "toggle_comment"
    Then the editor text should be:
      """
      <?php
      // foo(bar, baz);
      // $y = 2;
      """

  Scenario: A block comment wraps exactly the selection
    Given I select from line 2, column 5 to line 2, column 8
    When I use the action "toggle_block_comment"
    Then the editor should contain "foo(/* bar */, baz);"
