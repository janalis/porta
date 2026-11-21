@process
Feature: Launching the real porta command

  Scenario: Opening a file at a line from the command line
    Given a PHP project
    And the Welcome was already seen
    And a file "src/a.php" containing:
      """
      <?php
      // first
      // second
      """
    When I start the app with the arguments "src/a.php:3"
    Then I should eventually see "a.php"
    And I should see "// second"
