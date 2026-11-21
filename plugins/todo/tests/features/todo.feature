Feature: The TODO panel
  Show TODOs (Alt+8, or the View menu) scans the project for TODO, FIXME,
  HACK, XXX and BUG comments and lists them on the bottom dock; Enter opens
  a row's file at its line. The tags it scans for are a setting.

  Background:
    Given a PHP project
    And the bundled plugin "todo" is enabled
    And a file "src/a.php" containing:
      """
      <?php
      // TODO: write the tests
      // NOTE: keep this short
      echo 1;
      """
    And a file "src/b.php" containing:
      """
      <?php
      // FIXME: the off-by-one
      """

  @action:ide_todo
  Scenario: Show TODOs lists every marker in the project, and Enter opens one
    When I start the app with the arguments "src/a.php"
    And I use the action "ide_todo"
    Then I should see "write the tests" in "#results"
    And I should see "the off-by-one" in "#results"
    And I should not see "keep this short" in "#results"
    And the element "#results" should be focused
    When I press "Down"
    And I press "Enter"
    Then the editor should show "src/b.php"

  @setting:todo.tags
  Scenario: The tags to scan for are a setting
    Given the project configuration:
      """
      {"plugins": {"todo": {"tags": "NOTE"}}}
      """
    When I start the app with the arguments "src/a.php"
    And I use the action "ide_todo"
    Then I should see "keep this short" in "#results"
    And I should not see "write the tests" in "#results"
    And I should not see "the off-by-one" in "#results"
