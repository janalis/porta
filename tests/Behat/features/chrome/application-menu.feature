Feature: The application menu
  The menu bar along the top holds every command. F10 arms it with File lit;
  the arrows move along the titles, Enter drops one, and a title's underlined
  letter opens it. F10 or Esc lets go, and an item that cannot run now is
  greyed out and struck through.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      echo 1;
      """

  @action:ide_menu
  Scenario: The keyboard opens a menu and runs an item
    When I start the app with the arguments "a.php"
    And I type "X"
    Then the editor should contain "X<?php"
    When I press "F10"
    Then the element "#menu" should be focused
    And I should not see "Save All"
    When I press "Right"
    And I press "Enter"
    Then I should see "Undo"
    When I press "Enter"
    Then the editor text should be:
      """
      <?php
      echo 1;
      """
    And the element "#editor" should be focused

  Scenario: F10 again, or Esc, lets the menu go
    When I start the app with the arguments "a.php"
    And I press "F10"
    And I press "Enter"
    Then I should see "Save All"
    When I press "Escape"
    Then I should not see "Save All"
    And the element "#editor" should be focused
    When I press "F10"
    Then the element "#menu" should be focused
    When I press "F10"
    Then the element "#editor" should be focused

  Scenario: A title's underlined letter opens its menu
    When I start the app with the arguments "a.php"
    And I press "F10"
    And I press "h"
    Then I should see "Documentation"
    And I should see "Find Action"

  Scenario: A click on a title opens its menu and a click on an item runs it
    When I start the app with the arguments "a.php"
    And I click on the text "View"
    Then I should see "Show Problems"
    When I click on the text "Focus Project Tree"
    Then the element "#tree" should be focused
    And I should not see "Show Problems"

  Scenario: What needs an open file is greyed out without one
    When I start the app
    And I click on the text "Edit"
    Then I should see "Undo"
    And the text "Undo" should be struck through

  Scenario: With a file open the same items can run
    When I start the app with the arguments "a.php"
    And I click on the text "Edit"
    Then I should see "Undo"
    And the text "Undo" should not be struck through
