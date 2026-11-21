Feature: Run configurations
  The Run picker offers the current PHP file, the project's Composer scripts
  and its Makefile targets. Each run gets a terminal tab of its own, titled
  with its exit code once it ends, and Run Last runs it again in that tab.

  Background:
    Given a PHP project
    And the environment variable "SHELL" is "/bin/sh"
    And a file "run.php" containing:
      """
      <?php
      echo 'ran-', 6 * 7, PHP_EOL;
      """

  Scenario: Running the current file shows its output in a tab of its own
    Given I start the app with the arguments "run.php"
    When I use the action "ide_run"
    Then I should see "Run current file: run.php"
    When I press "Enter"
    Then I should see "ran-42"
    And I should see "Run current file: run.php (0)"

  Scenario: The picker lists the Makefile's targets, and runs one
    Given a file "Makefile" containing:
      """
      hello:
      	@echo made-$$((2+3))
      """
    And I start the app with the arguments "run.php"
    When I use the action "ide_run"
    Then I should see "make hello"
    When I type "make hello"
    And I press "Enter"
    Then I should see "made-5"

  Scenario: Run Last runs the same configuration again, in the same tab
    Given I start the app with the arguments "run.php"
    When I use the action "ide_run"
    And I press "Enter"
    Then I should see "ran-42"
    And I should see "Run current file: run.php (0)"
    When I focus "#editor"
    And I put the caret on line 3
    And I type "echo 'again-', 1 + 1, PHP_EOL;"
    And I use the action "ide_run_last"
    Then I should see "again-2"
    And I should see "Run current file: run.php" 1 times
    And the file "run.php" should contain "again-"
