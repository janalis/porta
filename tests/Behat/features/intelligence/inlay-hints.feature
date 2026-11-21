Feature: Inlay hints
  The language server names the parameters of a call and the types it infers.
  A parameter's name shows before a literal argument by default, as in
  PhpStorm; the settings and their commands say which hints show, and where.

  Background:
    Given a PHP project
    And the mock language server is configured
    And a file "src/Params.php" containing:
      """
      <?php
      f(42, $y);
      """
    And a file "src/TypesInlay.php" containing:
      """
      <?php
      $x = f();
      """

  @setting:inlayParameterHints
  Scenario: A parameter's name shows before a literal only
    When I start the app with the arguments "src/Params.php"
    Then I should see "f(n: 42, $y);"

  @setting:inlayParameterHints
  Scenario: Parameter name hints for every argument
    Given the project configuration:
      """
      {"settings": {"inlayParameterHints": "all"}}
      """
    When I start the app with the arguments "src/Params.php"
    Then I should see "f(n: 42, m: $y);"

  Scenario: Cycle Parameter Name Hints goes from literals to all to none
    Given I start the app with the arguments "src/Params.php"
    And I should see "f(n: 42, $y);"
    When I use the action "cycle_parameter_hints"
    Then I should see "f(n: 42, m: $y);"
    When I use the action "cycle_parameter_hints"
    Then I should see "f(42, $y);"

  Scenario: Hide Parameter Name Hints turns them off and the project remembers
    Given I start the app with the arguments "src/Params.php"
    And I should see "f(n: 42, $y);"
    When I use the action "hide_parameter_hints"
    Then I should see "f(42, $y);"
    And the project setting "inlayParameterHints" should be "none"

  Scenario: Only for literals switches between literals and all
    Given I start the app with the arguments "src/Params.php"
    And I should see "f(n: 42, $y);"
    When I use the action "toggle_parameter_hints_literals"
    Then I should see "f(n: 42, m: $y);"
    When I use the action "toggle_parameter_hints_literals"
    Then I should see "f(n: 42, $y);"

  @setting:inlayTypeHints
  Scenario: An inferred type shows after the variable
    When I start the app with the arguments "src/TypesInlay.php"
    Then I should see "$x: int"

  @setting:inlayTypeHints
  Scenario: Type hints can be turned off in the configuration
    Given the project configuration:
      """
      {"settings": {"inlayTypeHints": false}}
      """
    And I start the app with the arguments "src/TypesInlay.php"
    When the language server is ready
    Then I should see "x= $x = f();"
    And I should not see ": int"

  Scenario: Toggle Type Hints turns the type hints off and on
    Given I start the app with the arguments "src/TypesInlay.php"
    And I should see "$x: int"
    When I use the action "toggle_type_hints"
    Then I should not see ": int"
    When I use the action "toggle_type_hints"
    Then I should see "$x: int"

  Scenario: Hide Type Hints turns the type hints off
    Given I start the app with the arguments "src/TypesInlay.php"
    And I should see "$x: int"
    When I use the action "hide_type_hints"
    Then I should not see ": int"
    And the project setting "inlayTypeHints" should be "false"

  @setting:inlayHints
  Scenario: Inlay hints can be switched off altogether
    Given the project configuration:
      """
      {"settings": {"inlayHints": "none"}}
      """
    When I start the app with the arguments "src/Params.php"
    And the language server is ready
    Then I should see "f(42, $y);"
    And I should not see "n: 42"

  @setting:inlayHints
  Scenario: As tooltips the hints leave the line
    Given the project configuration:
      """
      {"settings": {"inlayHints": "tooltip"}}
      """
    When I start the app with the arguments "src/Params.php"
    And the language server is ready
    Then I should see "f(42, $y);"

  Scenario: Cycle Inlay Hints goes inline, tooltip, none, and back inline
    Given I start the app with the arguments "src/TypesInlay.php"
    And I should see "$x: int"
    When I use the action "cycle_inlay_hints"
    Then I should not see ": int"
    When I use the action "cycle_inlay_hints"
    And I use the action "cycle_inlay_hints"
    Then I should see "$x: int"

  Scenario: Hide All Inlay Hints turns every hint off
    Given I start the app with the arguments "src/Params.php"
    And I should see "f(n: 42, $y);"
    When I use the action "hide_inlay_hints"
    Then I should see "f(42, $y);"
    And the project setting "inlayHints" should be "none"
