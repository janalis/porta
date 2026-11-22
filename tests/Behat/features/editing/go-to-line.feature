Feature: Go to line
  Ctrl+G asks for a line, and line:column, and puts the caret there.

  Background:
    Given a PHP project
    And a file "long.php" containing 100 numbered lines
    And I start the app with the arguments "long.php"

  Scenario: Go to a line far down the file
    When I use the action "goto_line"
    And I type "80"
    And I press "Enter"
    Then the caret should be on line 80
    And I should see "// line 79"

  Scenario: Go to a line and a column
    When I use the action "goto_line"
    And I type "12:4"
    And I press "Enter"
    Then the caret should be at line 12, column 4
