Feature: Conditions, watches, evaluation and exception breakpoints
  A breakpoint can carry a condition, the debug panel re-evaluates watch
  expressions at every stop, a one-shot expression can be evaluated against
  the paused frame, and the adapter's exception filters are a checklist. The
  scripted adapter echoes what it received on its console, the
  "Debug output" tab.

  Background:
    Given a PHP project
    And the scripted debug adapter is configured for PHP
    And a file "prog.php" containing:
      """
      <?php
      // l2
      // l3
      // l4
      // l5
      // l6
      // l7
      // l8
      """

  Scenario: A conditional breakpoint is a diamond, and its condition reaches the adapter
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 5
    And I use the action "debug_edit_breakpoint"
    And I type "$x > 1"
    And I press "Enter"
    And I press "Enter"
    And I press "Enter"
    Then I should see "◆"
    When I use the action "ide_debug"
    Then I should see "paused at prog.php:5"
    When I click on the text "Debug output: prog.php"
    Then I should see "cond@5: $x > 1"
    When I stop the app
    Then the file ".porta/debug.json" should contain "$x > 1"

  Scenario: A watch is evaluated at every stop
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:3"
    When I use the action "debug_add_watch"
    And I type "$x"
    And I press "Enter"
    Then I should see "Watches"
    And I should see "$x = 42#1"
    When I use the action "debug_step_over"
    Then I should see "$x = 42#2"

  Scenario: Evaluating an expression against the paused frame
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:3"
    When I use the action "debug_evaluate"
    And I type "$x"
    And I press "Enter"
    Then I should see "$x = 42#1"

  Scenario: Pausing a running program stops it where it is
    Given a file "prog.php" containing:
      """
      <?php
      // l2
      // l3
      // keep running
      """
    And I start the app with the arguments "prog.php"
    When I put the caret on line 2
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "⏸ prog.php:2"
    When I use the action "debug_continue"
    Then I should see "▶ debug"
    When I use the action "debug_pause"
    Then I should see "⏸ prog.php:2"

  @command:debug_exception_breakpoints
  Scenario: Exception breakpoints are the adapter's filters, toggled live
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:3"
    When I find the action "Debug: Exception Breakpoints"
    And I press "Enter"
    Then I should see "Exception Breakpoints"
    And I should see "Uncaught Exceptions"
    And I should see "Warnings"
    When I press "Enter"
    And I press "Escape"
    And I click on the text "Debug output: prog.php"
    Then I should see "xfilters: exception,warning"
    When I stop the app
    Then the file ".porta/debug.json" should contain "warning"
