Feature: Breakpoints and stepping through a PHP file
  Porta speaks the Debug Adapter Protocol to any adapter configured for the
  language. Here the adapter is a scripted one: it stops on the first
  breakpoint, steps one line at a time, and says on its console what it was
  asked to do.

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

  Scenario: A breakpoint is a dot in the gutter, toggled on the caret's line
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    Then I should see "●"
    When I use the action "debug_toggle_breakpoint"
    Then I should not see "●"

  Scenario: Debugging stops at the breakpoint and shows the frames and the locals
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 5
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:5"
    And I should see "#0 {main}  prog.php:5"
    And I should see "#1 require  prog.php:1"
    And I should see "$x = 42"
    And I should see "⏸ prog.php:5"

  Scenario: Step over, into and out each advance the paused program
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:3"
    When I use the action "debug_step_over"
    Then I should see "paused at prog.php:4"
    When I use the action "debug_step_into"
    Then I should see "paused at prog.php:5"
    When I use the action "debug_step_out"
    Then I should see "paused at prog.php:6"

  Scenario: Resuming runs to the next breakpoint, then to the end
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I put the caret on line 6
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:3"
    When I use the action "debug_continue"
    Then I should see "paused at prog.php:6"
    When I use the action "debug_continue"
    Then I should see "terminated"

  Scenario: Run to cursor stops once at the caret's line
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:3"
    When I focus "#editor"
    And I put the caret on line 7
    And I use the action "debug_run_to_cursor"
    Then I should see "paused at prog.php:7"
    When I use the action "debug_continue"
    Then I should see "terminated"

  Scenario: Stopping the debugger ends the session
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "⏸ prog.php:3"
    When I use the action "debug_stop"
    Then I should not see "⏸ prog.php:3"
    And I should not see "paused at prog.php:3"

  Scenario: Restarting the debugger starts the program over
    Given I start the app with the arguments "prog.php"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:3"
    When I use the action "debug_step_over"
    Then I should see "paused at prog.php:4"
    When I use the action "debug_restart"
    And I click on the text "Debug output: prog.php"
    Then I should see "restarted"

  Scenario: Debugging with no adapter configured says so
    Given the project configuration:
      """
      {"languages": {"php": {"debug": {"adapter": ""}}}}
      """
    And I start the app with the arguments "prog.php"
    When I use the action "ide_debug"
    Then I should see "No debug adapter configured for php"
