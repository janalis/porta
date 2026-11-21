Feature: A diagram too large to edit
  A diagram over the 2 MB the editor takes still opens, as a picture with
  no text beside it. Open as Text then shows its source read-only: the
  beginning of it, with a banner saying so.

  @action:open_as_text
  Scenario: Open as Text shows the beginning of a diagram too large to edit
    Given a PHP project
    And the bundled plugins are available
    And only PHP is on the PATH
    And the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"dotPath": "%fakes%/fake-dot"}}}
      """
    And a file "huge.dot" of more than 2 MB beginning with "digraph Huge { A -> B; B -> C; }"
    And I start the app with the arguments "huge.dot"
    Then I should see "3 nodes · 2 edges"
    And I should not see "[Split]"
    When I use the action "open_as_text"
    Then I should see "digraph Huge { A -> B; B -> C; }"
