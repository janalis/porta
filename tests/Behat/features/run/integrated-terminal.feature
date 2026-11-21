Feature: The integrated terminal
  A bottom panel runs a real interactive shell on a pseudo-terminal. Every
  session is a tab of the bottom dock with its own scrollback and process:
  open another, cycle between them, close them one by one.

  The shell here is /bin/sh, and each command prints what it computes
  ("echo one-$((0+1))" prints "one-1"), so the output on screen is the
  shell's answer, not the echo of what was typed.

  Background:
    Given a PHP project
    And a file "a.php" containing "<?php"
    And the environment variable "SHELL" is "/bin/sh"

  Scenario: The terminal runs what is typed into its shell
    Given I start the app with the arguments "a.php"
    When I use the action "ide_terminal"
    And I type "echo one-$((0+1))"
    And I press "Enter"
    Then I should see "one-1"

  Scenario: Hiding the panel keeps the shell running
    Given I start the app with the arguments "a.php"
    When I use the action "ide_terminal"
    And I type "echo one-$((0+1))"
    And I press "Enter"
    Then I should see "one-1"
    When I use the action "ide_terminal"
    Then I should not see "one-1"
    When I use the action "ide_terminal"
    Then I should see "one-1"

  Scenario: Each session keeps its own output, and the keys cycle between them
    Given I start the app with the arguments "a.php"
    When I use the action "ide_terminal"
    And I type "echo one-$((0+1))"
    And I press "Enter"
    Then I should see "one-1"
    When I use the action "ide_terminal_new"
    And I type "echo two-$((1+1))"
    And I press "Enter"
    Then I should see "two-2"
    And I should not see "one-1"
    When I use the action "terminal_prev_session"
    Then I should see "one-1"
    And I should not see "two-2"
    When I use the action "terminal_next_session"
    Then I should see "two-2"
    And I should not see "one-1"

  Scenario: Closing sessions one by one, and the last closes the panel
    Given I start the app with the arguments "a.php"
    When I use the action "ide_terminal"
    And I type "echo one-$((0+1))"
    And I press "Enter"
    Then I should see "one-1"
    When I use the action "ide_terminal_new"
    And I type "echo two-$((1+1))"
    And I press "Enter"
    Then I should see "two-2"
    When I use the action "terminal_close_session"
    Then I should see "one-1"
    And I should not see "two-2"
    When I use the action "terminal_close_session"
    Then I should not see "one-1"
    And the element "#editor" should be focused
