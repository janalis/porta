Feature: Scratch files
  Ctrl+Alt+Shift+S asks which language and opens an empty buffer for it,
  kept in <home>/.porta/scratches rather than in the project, named per
  language: scratch_1.php, scratch_2.php, scratch_1.md.

  Scenario: A PHP scratch file opens outside the project
    Given a PHP project
    And a file "a.php" containing "<?php // a"
    And the environment variable "PORTA_HOME" is "%workspace%/home"
    And I start the app with the arguments "a.php"
    When I use the action "ide_new_scratch"
    Then I should see "New Scratch File"
    When I press "Enter"
    Then the editor should show "home/.porta/scratches/scratch_1.php"
    And the file "home/.porta/scratches/scratch_1.php" should exist
    When I type "<?php echo 1;"
    And I use the action "save"
    Then the file "home/.porta/scratches/scratch_1.php" should contain "echo 1;"
    When I use the action "ide_new_scratch"
    And I press "Enter"
    Then the editor should show "home/.porta/scratches/scratch_2.php"
