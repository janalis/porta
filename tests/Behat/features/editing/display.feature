Feature: What the editor draws besides the code
  Line numbers can be absolute, relative or off; sticky scroll pins the
  enclosing class and method over the code; indent guides, method separators
  and leading and trailing whitespace can each be shown; soft wrap continues a
  long line on the next rows. Each one is a setting in .porta.json and in
  Settings → Interface, and the View menu toggles the ones with keys.

  Background:
    Given a PHP project
    And a file "Service.php" containing:
      """
      <?php

      class Service
      {
          public function handle(): void
          {
              $x = 1;   
              $y = 2;
          }

          public function other(): void
          {
              $z = 3;
          }
      }
      """

  @setting:lineNumbers
  Scenario: Line numbers off
    Given the project configuration:
      """
      {"settings": {"lineNumbers": "off"}}
      """
    And I start the app with the arguments "Service.php"
    Then I should see "class Service"
    And I should not see text matching "/│\s+3\s+class Service/"

  Scenario: Cycle the line numbers through relative and off
    Given I start the app with the arguments "Service.php"
    And the caret is at line 7, column 1
    Then I should see text matching "/│\s+3\s+class Service/"
    When I use the action "cycle_line_numbers"
    Then I should see text matching "/│\s+4\s+class Service/"
    When I use the action "cycle_line_numbers"
    Then I should not see text matching "/│\s+\d+\s+class Service/"
    And the project setting "lineNumbers" should be "off"

  @setting:stickyScroll
  Scenario: Sticky scroll pins the class over a method scrolled past its header
    Given a file "Long.php" containing a class with a 60-line method
    And I start the app with the arguments "Long.php"
    When I go to line 50
    Then I should see "class Long"
    And I should see "public function handle()"
    When I restart the app with the project configuration:
      """
      {"settings": {"stickyScroll": false}}
      """
    And I go to line 50
    Then I should see "$x = 1;"
    And I should not see "class Long"

  @setting:indentGuides
  Scenario: Indent guides mark each indent level, unless turned off
    Given I start the app with the arguments "Service.php"
    Then I should see text matching "/8\s+│   │   \$y = 2;/"
    When I restart the app with the project configuration:
      """
      {"settings": {"indentGuides": false}}
      """
    Then I should see text matching "/8\s+\$y = 2;/"
    And I should not see text matching "/│   │   \$y/"

  @setting:showWhitespace
  Scenario: Trailing whitespace shows as dots
    Given the project configuration:
      """
      {"settings": {"showWhitespace": true}}
      """
    And I start the app with the arguments "Service.php"
    Then I should see "$x = 1;···"

  @setting:methodSeparators
  Scenario: A rule closes off each method
    Given the project configuration:
      """
      {"settings": {"methodSeparators": true, "indentGuides": false}}
      """
    And I start the app with the arguments "Service.php"
    Then the text "    }" should be underlined

  @setting:softWrap
  Scenario: Soft wrap continues a long line on the next row
    Given a file "wide.php" containing a line of 200 characters ending in "THE-END"
    And the project configuration:
      """
      {"settings": {"softWrap": true}}
      """
    And I start the app with the arguments "wide.php"
    Then I should see "THE-END"

  Scenario: Alt+Z toggles soft wrap and saves it
    Given a file "wide.php" containing a line of 200 characters ending in "THE-END"
    And I start the app with the arguments "wide.php"
    Then I should not see "THE-END"
    When I use the action "toggle_soft_wrap"
    Then I should see "THE-END"
    And the project setting "softWrap" should be true

  Scenario: Code Vision is a View menu toggle
    Given I start the app with the arguments "Service.php"
    When I use the action "toggle_code_lenses"
    And I click on the text "View"
    Then I should see "✓ Code Vision"
    When I press "Escape"
    And I use the action "toggle_code_lenses"
    And I click on the text "View"
    Then I should not see "✓ Code Vision"
