Feature: Notifications
  What the IDE has to say shows as a card in the corner for a while. Alt+D
  dismisses the newest card, its ✖ closes it, and the notification log
  (Alt+9) keeps every one of them for when they were missed.

  Background:
    Given a PHP project
    And a file "a.php" containing "<?php"

  @action:ide_dismiss_toast
  Scenario: Alt+D dismisses the newest card
    When I start the app with the arguments "a.php"
    And I use the action "ide_trust_project"
    Then I should see "This project is trusted already"
    When I use the action "ide_dismiss_toast"
    Then I should not see "This project is trusted already"

  Scenario: A card's ✖ closes it
    When I start the app with the arguments "a.php"
    And I use the action "ide_trust_project"
    Then I should see "This project is trusted already"
    # The card's top border shares the row under the menu bar with the tabs' own ✖
    When I click on the last "✖" on line 3
    Then I should not see "This project is trusted already"

  @action:ide_notifications
  Scenario: The notification log keeps what the cards said
    When I start the app with the arguments "a.php"
    And I use the action "ide_trust_project"
    And I should see "This project is trusted already"
    And I use the action "ide_dismiss_toast"
    And I should not see "This project is trusted already"
    When I use the action "ide_notifications"
    Then I should see "This project is trusted already" in "#results"
