Feature: Trusting a project
  A project can run code of its own, so Porta asks before it runs any of it.
  An untrusted project opens restricted: no language server starts, and the
  status bar says why. Trust This Project and Open This Project Restricted, in
  Find Action, change the answer and reopen the project.

  Background:
    Given a PHP project
    And a file "a.php" containing "<?php"

  @command:ide_trust_project
  Scenario: Trusting a restricted project reopens it with its own say believed
    Given the project was opened restricted
    When I start the app with the arguments "a.php"
    Then I should eventually see "project not trusted"
    When I use the action "ide_trust_project"
    Then the IDE should close to reopen the project
    And the project should be trusted from now on
    When I restart the app
    Then I should eventually see "a.php"
    And I should not see "project not trusted"

  @command:ide_restrict_project
  Scenario: Restricting a trusted project reopens it restricted
    Given the project was trusted
    When I start the app with the arguments "a.php"
    Then I should eventually see "a.php"
    And I should not see "project not trusted"
    When I use the action "ide_restrict_project"
    Then the IDE should close to reopen the project
    And the project should open restricted from now on
    When I restart the app
    Then I should eventually see "project not trusted"

  Scenario: Trusting a project already trusted only says so
    Given the project was trusted
    When I start the app with the arguments "a.php"
    And I use the action "ide_trust_project"
    Then I should eventually see "This project is trusted already"
    And the app should be running
