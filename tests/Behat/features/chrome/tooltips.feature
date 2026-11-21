Feature: Tooltips
  When the pointer rests on a glyph, a toggle or a status cell, a card says
  what it means. The tooltips setting turns the cards off, and tooltipDelay
  is how long the pointer rests before one shows.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      echo 1;
      """

  Scenario: Resting on a status cell says what it does
    When I start the app with the arguments "a.php"
    And I hover over the text "1:1"
    Then I should eventually see "click to go to a line"

  @setting:tooltips
  Scenario: With tooltips off, resting the pointer shows nothing
    Given the project configuration:
      """
      {"settings": {"tooltips": false, "tooltipDelay": 0}}
      """
    When I start the app with the arguments "a.php"
    And I hover over the text "1:1"
    # A real clock: the card would show after its delay, here none at all
    And I wait 1 second
    Then I should not see "click to go to a line"

  @setting:tooltipDelay
  Scenario: The pointer has to rest for the delay before the card shows
    Given the project configuration:
      """
      {"settings": {"tooltipDelay": 2000}}
      """
    When I start the app with the arguments "a.php"
    And I hover over the text "1:1"
    # A real clock: one second into a two-second delay, no card yet
    And I wait 1 second
    Then I should not see "click to go to a line"
    When I wait 1.5 seconds
    Then I should see "click to go to a line"
