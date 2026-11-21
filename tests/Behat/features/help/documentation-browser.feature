Feature: The documentation browser
  F1 opens Porta's guides, fully rendered, in a window of their own. Tab and
  Shift+Tab walk the page's links, Enter follows one in place, Backspace goes
  back, and the arrows, PageUp/PageDown and Home/End scroll the page.

  Background:
    Given a PHP project
    And a file "a.php" containing "<?php"

  @action:ide_help
  Scenario: F1 opens the documentation's front page, rendered
    When I start the app with the arguments "a.php"
    And I use the action "ide_help"
    Then I should eventually see "Porta documentation"
    And I should see "Using Porta"
    And I should not see "## Using Porta"
    And I should not see "]("
    And the element "#help-viewer" should be focused

  @action:help_next_link @action:help_prev_link @action:help_follow_link @action:help_back
  Scenario: The links are walked with Tab, followed with Enter, and Backspace goes back
    When I start the app with the arguments "a.php"
    And I use the action "ide_help"
    And I should eventually see "Porta documentation"
    And I use the action "help_next_link"
    And I use the action "help_next_link"
    And I use the action "help_next_link"
    And I use the action "help_next_link"
    And I use the action "help_prev_link"
    Then the focused link should lead to "getting-started.md"
    When I use the action "help_follow_link"
    Then the documentation should show "getting-started.md"
    And I should eventually see "Requirements"
    When I use the action "help_back"
    Then the documentation should show "README.md"
    And I should see "Porta documentation"

  @action:help_scroll_down @action:help_scroll_up @action:help_page_down @action:help_page_up @action:help_end @action:help_home
  Scenario: The page scrolls by a line, by a page, and to either end
    When I start the app with the arguments "a.php"
    And I use the action "ide_help"
    And I should eventually see "Porta documentation"
    When I use the action "help_scroll_down"
    And I use the action "help_scroll_down"
    And I use the action "help_scroll_down"
    Then I should not see "Porta documentation"
    When I use the action "help_scroll_up"
    And I use the action "help_scroll_up"
    And I use the action "help_scroll_up"
    Then I should see "Porta documentation"
    When I use the action "help_page_down"
    Then I should not see "Porta documentation"
    When I use the action "help_page_up"
    Then I should see "Porta documentation"
    When I use the action "help_end"
    Then I should not see "Porta documentation"
    When I use the action "help_home"
    Then I should see "Porta documentation"
