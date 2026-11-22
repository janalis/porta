@process
Feature: Launching the real porta command
  `porta [path|file:line]` opens a project in the terminal it is run in. These
  scenarios run the real command in a pseudo-terminal: what it asks before the
  IDE takes the screen, what its options do, and how it ends.

  Background:
    Given a PHP project
    And the environment variable "PORTA_HOME" is "%workspace%/home"
    And a directory "home"

  Scenario: Opening a file at a line from the command line
    Given the Welcome was already seen
    And a file "src/a.php" containing:
      """
      <?php
      // first
      // second
      """
    When I start the app with the arguments "src/a.php:3"
    Then I should eventually see "a.php 3:1"

  Scenario: --line opens the file scrolled to that line
    Given the Welcome was already seen
    And a file "src/a.php" containing:
      """
      <?php
      // first
      // second
      """
    When I start the app with the arguments "src/a.php --line 2"
    Then I should eventually see "a.php 2:1"

  Scenario: An untrusted project asks before anything of its own runs
    Given the Welcome was already seen
    And the environment variable "PORTA_TRUSTED_FOLDERS" is ""
    When I start the app
    Then I should see "Trust this project?"
    When I type "y"
    And I press "Enter"
    Then I should eventually see "File"
    And the file "home/.porta/trusted-projects.json" should exist
    When I restart the app
    Then I should eventually see "File"
    And I should not see "Trust this project?"

  Scenario: Saying no opens the project restricted, and the answer is kept
    Given the Welcome was already seen
    And the environment variable "PORTA_TRUSTED_FOLDERS" is ""
    When I start the app
    And I press "Enter"
    Then I should eventually see "File"
    And the file "home/.porta/trusted-projects.json" should exist
    When I restart the app
    Then I should eventually see "File"
    And I should not see "Trust this project?"

  Scenario: Contradictory options are refused before the IDE starts
    When I start the app with the arguments "--lsp phpactor --no-lsp"
    Then I should see "--lsp and --no-lsp contradict each other"
    And the app should have exited with code 2

  Scenario: One path at a time
    Given a file "a.php" containing "<?php"
    And a file "b.php" containing "<?php"
    When I start the app with the arguments "a.php b.php"
    Then I should see "one file or directory at a time"
    And the app should have exited with code 2

  Scenario: A path that does not exist
    When I start the app with the arguments "nowhere.php"
    Then I should see "no such file or directory: nowhere.php"
    And the app should have exited

  Scenario: Quitting ends the process cleanly
    Given the Welcome was already seen
    When I start the app
    And I should eventually see "File"
    And I press "Ctrl+Q"
    Then the app should have exited with code 0

  Scenario: Quitting with unsaved changes asks again, and a second Ctrl+Q discards
    Given the Welcome was already seen
    And a file "a.php" containing "<?php"
    When I start the app with the arguments "a.php"
    And I should eventually see "a.php"
    And I type "// unsaved"
    And I press "Ctrl+Q"
    Then the app should be running
    When I press "Ctrl+Q"
    Then the app should have exited with code 0
    And the file "a.php" should not contain "unsaved"

  Scenario: The first run in a project opens the Welcome
    When I start the app
    Then I should eventually see "Welcome to Porta"
