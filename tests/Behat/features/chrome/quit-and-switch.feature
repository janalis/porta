Feature: Quitting and switching projects
  Ctrl+Q quits; with unsaved changes it asks for a second press within three
  seconds. Open Project (Ctrl+Shift+O) closes this project the way a quit
  does and reopens the IDE on another one; a right-click on the project's
  name offers the folders beside it.

  Background:
    Given a PHP project
    And a file "a.php" containing "<?php"
    And a file "other/b.php" containing "<?php"

  @action:ide_quit
  Scenario: Ctrl+Q quits
    When I start the app with the arguments "a.php"
    And I use the action "ide_quit"
    Then the app should have exited

  @action:ide_quit
  Scenario: With unsaved changes Ctrl+Q asks to be pressed again
    When I start the app with the arguments "a.php"
    And I type "x"
    And I use the action "ide_quit"
    Then I should see "Unsaved changes"
    And the app should be running
    When I use the action "ide_quit"
    Then the app should have exited
    And the file "a.php" should contain exactly:
      """
      <?php
      """

  @action:ide_open_project
  Scenario: Open Project reopens the IDE on the path typed
    Given no project was opened before
    When I start the app with the arguments "a.php"
    And I use the action "ide_open_project"
    Then I should see "Open project (path)"
    When I type the path of the folder "other"
    And I press "Enter"
    Then the IDE should close to reopen the project in "other"
    And the app should have exited

  Scenario: Open Project refuses what is not a folder
    Given no project was opened before
    When I start the app with the arguments "a.php"
    And I use the action "ide_open_project"
    And I type the path of the folder "missing"
    And I press "Enter"
    Then I should see "Not a directory"
    And the app should be running
