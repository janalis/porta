Feature: Live preview
  A Markdown file opens as text with its rendered page beside it. The
  toggles at the right of the breadcrumbs bar choose the text alone, both,
  or the page alone; Ctrl+Alt+V steps through the three, and the View menu's
  Editor Only / Editor and Preview / Preview Only pick one. The page follows
  what is typed, saved or not.

  Background:
    Given a PHP project
    And a file "notes.md" containing:
      """
      # Shopping list

      - [ ] apples
      - [x] pears
      """

  Scenario: A Markdown file opens beside its rendered page
    Given I start the app with the arguments "notes.md"
    Then I should see "[Split]"
    And I should see "# Shopping list"
    And I should see "• [ ] apples"

  Scenario: Toggling the preview steps through the three arrangements
    Given I start the app with the arguments "notes.md"
    Then I should see "[Split]"
    When I use the action "toggle_preview"
    Then I should see "[Preview]"
    And I should not see "# Shopping list"
    When I use the action "toggle_preview"
    Then I should see "[Editor]"
    And I should see "# Shopping list"
    When I use the action "toggle_preview"
    Then I should see "[Split]"

  Scenario: The View menu picks each arrangement
    Given I start the app with the arguments "notes.md"
    When I use the action "preview_only"
    Then I should see "[Preview]"
    When I use the action "preview_editor"
    Then I should see "[Editor]"
    And I should not see "• [ ] apples"
    When I use the action "preview_split"
    Then I should see "[Split]"
    And I should see "• [ ] apples"

  Scenario: The page follows what is typed, before it is saved
    Given I start the app with the arguments "notes.md"
    When I put the caret on line 4
    And I press "End"
    And I press "Enter"
    And I type "- [ ] cherries"
    Then I should see "• [ ] cherries"
    And the file "notes.md" should not contain "cherries"
