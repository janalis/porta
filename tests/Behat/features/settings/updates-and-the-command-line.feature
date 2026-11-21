Feature: Updating Porta, and Porta outside the IDE
  Check for Updates asks for the latest stable release, and says what it
  found. In the background Porta asks at the ideUpdateCheck cadence; with
  ideAutoUpdate on, an install that updates itself is updated once work stops,
  while a source checkout is only told. Settings › Command line installs the
  porta command (a link in ~/.local/bin unless one is on the PATH), and takes
  two presses to remove it.

  The network here is a stand-in: an address the scenario gives answers, any
  other fails.

  Background:
    Given a PHP project
    And a Porta home of my own
    And a file "a.php" containing "<?php"

  Scenario: Check for Updates says a newer release is out
    Given the address "https://api.github.com/repos/janalis/porta/releases/latest" answers:
      """
      {"tag_name": "v99.0.0", "prerelease": false, "draft": false, "assets": []}
      """
    And I start the app with the arguments "a.php"
    When I use the action "ide_check_updates"
    Then a notification should say "Porta v99.0.0 is available. Update from Settings › Command line."

  Scenario: Check for Updates says so when the release cannot be read
    Given the network is out of reach
    And I start the app with the arguments "a.php"
    When I use the action "ide_check_updates"
    Then a notification should say "Could not check for updates."
    And "https://api.github.com/repos/janalis/porta/releases/latest" should have been asked for

  @setting:ideUpdateCheck
  Scenario: A due background check offers the newer release on its own
    Given the address "https://api.github.com/repos/janalis/porta/releases/latest" answers:
      """
      {"tag_name": "v99.0.0", "prerelease": false, "draft": false, "assets": []}
      """
    And Porta was last checked for updates long ago
    When I start the app with the arguments "a.php"
    Then a notification should say "Porta v99.0.0 is available."

  @setting:ideUpdateCheck
  Scenario: With the update check off nothing is asked, until it is switched on
    Given the address "https://api.github.com/repos/janalis/porta/releases/latest" answers:
      """
      {"tag_name": "v99.0.0", "prerelease": false, "draft": false, "assets": []}
      """
    And Porta was last checked for updates long ago
    And the project configuration:
      """
      {"settings": {"ideUpdateCheck": "off"}}
      """
    And I start the app with the arguments "a.php"
    When I open the Settings page "General"
    Then nothing should have been asked of the network
    When I choose "weekly" for the setting "IDE update check"
    Then a notification should say "Porta v99.0.0 is available."
    And the project configuration should have "settings.ideUpdateCheck" set to "weekly"

  @setting:ideAutoUpdate
  Scenario: With auto-update on, a source checkout is still only told
    Given the address "https://api.github.com/repos/janalis/porta/releases/latest" answers:
      """
      {"tag_name": "v99.0.0", "prerelease": false, "draft": false, "assets": []}
      """
    And Porta was last checked for updates long ago
    And I start the app with the arguments "a.php"
    When I open the Settings page "General"
    And I select the setting "Auto-update IDE"
    And I press "Space"
    Then the project configuration should have "settings.ideAutoUpdate" set to "true"
    When I press "Escape"
    And I use the action "ide_check_updates"
    Then a notification should say "Porta v99.0.0 is available. Update from Settings › Command line."
    And the app should be running

  Scenario: Install the porta Command… links porta into ~/.local/bin, and removing it takes two presses
    Given only the stand-ins are on the PATH
    And the environment variable "HOME" is "%workspace%/home"
    And I start the app with the arguments "a.php"
    When I use the action "ide_install_command"
    Then I should see "Settings › Command line"
    When I select the setting "porta in a terminal"
    And I press "Enter"
    Then a notification should say "porta is now a command"
    And the file "home/.local/bin/porta" should exist
    When I select the setting "Remove the porta command"
    And I press "Enter"
    Then a notification should say "Press Enter again to remove it"
    And the file "home/.local/bin/porta" should exist
    When I press "Enter"
    Then the file "home/.local/bin/porta" should not exist
