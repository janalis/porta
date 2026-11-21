Feature: Git in the editor
  Inside a git working tree the gutter marks what differs from HEAD — added,
  modified, deleted — and the change keys walk those marks. A change can be
  shown and reverted where it stands, and blame says who wrote a line: one
  line in a flash, or every line as a dim annotation.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And a file "a.txt" containing:
      """
      a
      b
      c
      d
      e
      f
      """
    And a git repository with everything committed

  Scenario: Lines changed since the last commit are marked in the gutter
    Given a file "a.txt" containing:
      """
      a
      B
      c
      d
      e
      f
      g
      """
    When I start the app with the arguments "a.txt"
    Then the gutter should mark line 2 as "modified"
    And the gutter should mark line 7 as "added"
    And I should see "┆  2   B"
    And I should see "▎  7   g"

  @setting:git.gutterMarks
  Scenario: The change marks can be turned off
    Given a file "a.txt" containing:
      """
      a
      B
      c
      d
      e
      f
      """
    And the project configuration:
      """
      {"plugins": {"git": {"gutterMarks": false}}}
      """
    When I start the app with the arguments "a.txt"
    # The strip's column is gone: the line number follows the pane's border
    Then I should see "│  2   B"
    And the gutter should mark no line

  Scenario: The change keys walk the changes, wrapping at either end
    Given a file "a.txt" containing:
      """
      a
      B
      c
      d
      E
      f
      """
    And I start the app with the arguments "a.txt"
    And the gutter should mark line 5 as "modified"
    When I use the action "next_change"
    Then the caret should be on line 2
    When I use the action "next_change"
    Then the caret should be on line 5
    When I use the action "next_change"
    Then the caret should be on line 2
    When I use the action "prev_change"
    Then the caret should be on line 5

  Scenario: A change is shown with what it removed, and reverted in the buffer
    Given a file "a.txt" containing:
      """
      a
      b
      c
      d
      E
      f
      """
    And I start the app with the arguments "a.txt"
    And the gutter should mark line 5 as "modified"
    When I use the action "next_change"
    And I use the action "git_show_change"
    Then I should see "Revert this change"
    And I should see "− e"
    And I should see "+ E"
    When I press "Enter"
    Then line 5 of the editor should be "e"
    And a toast should say "Change reverted"

  Scenario: Showing the change on an unchanged line says there is none
    Given a file "a.txt" containing:
      """
      a
      B
      c
      d
      e
      f
      """
    And I start the app with the arguments "a.txt"
    And the gutter should mark line 2 as "modified"
    When I put the caret on line 4
    And I use the action "git_show_change"
    Then a toast should say "No change here"

  Scenario: Blame says who wrote the caret's line, and in which commit
    Given I start the app with the arguments "a.txt"
    When I use the action "git_blame"
    Then a toast should say "Behat: initial"

  Scenario: Blame annotations are toggled on every line, and off again
    Given I start the app with the arguments "a.txt"
    When I use the action "git_toggle_blame"
    Then a toast should say "Blame annotations shown"
    And I should see "Behat" 6 times
    When I use the action "git_toggle_blame"
    Then I should not see "Behat"

  @setting:git.blameDefault
  Scenario: Files can open with their blame annotations on
    Given the project configuration:
      """
      {"plugins": {"git": {"blameDefault": true}}}
      """
    When I start the app with the arguments "a.txt"
    Then I should see "Behat" 6 times
