Feature: Your name, and showing others where you are
  Every Porta at work on the same folder leaves itself on the presence board
  in .porta/presence: its user's name, open file and caret. The others draw
  it as a named caret and count it in the status bar. displayName is the name
  they see; with sharePresence off, this Porta still sees them, and they no
  longer see it.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $a = 1;
      """
    And another Porta is at work on "a.php" as "Ada"

  Scenario: Another Porta on the folder is drawn by name and counted
    When I start the app with the arguments "a.php"
    Then I should see "✦ Ada"
    And I should see "⚇ 1"

  @setting:displayName
  Scenario: This Porta is on the board under the name chosen
    Given the project configuration:
      """
      {"settings": {"displayName": "Hal"}}
      """
    When I start the app with the arguments "a.php"
    Then this Porta should be on the presence board as "Hal"

  @setting:displayName
  Scenario: A name changed on the General page is the one the others see
    Given I start the app with the arguments "a.php"
    When I open the Settings page "General"
    And I set the setting "Your name" to "Grace"
    Then the project configuration should have "settings.displayName" set to "Grace"
    And this Porta should be on the presence board as "Grace"

  @setting:sharePresence
  Scenario: With sharePresence off, the others are still shown, and this Porta is not on the board
    Given the project configuration:
      """
      {"settings": {"displayName": "Hal", "sharePresence": false}}
      """
    When I start the app with the arguments "a.php"
    Then I should see "✦ Ada"
    And only "Ada" should be on the presence board
