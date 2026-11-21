Feature: The merge view
  A file holding conflict markers opens as three columns: ours on the left,
  the result in the editor between, theirs on the right, each conflict's
  first row on a side labelled with the branch git named. A click on a
  label keeps that side for the conflict, as one undo step; the result stays
  the editor's text.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And a file "m.php" containing:
      """
      <?php
      <<<<<<< HEAD
      $a = 'ours';
      =======
      $a = 'theirs';
      >>>>>>> topic
      $b = 1;
      """

  Scenario: A conflicted file opens as ours, the result and theirs
    When I start the app with the arguments "m.php"
    Then I should see "⇥ HEAD"
    And I should see "⇤ topic"
    And I should see "⇥ HEAD" before "<<<<<<< HEAD"
    And I should see "<<<<<<< HEAD" before "⇤ topic"

  Scenario: A click on a side's label keeps it, and one undo puts the conflict back
    Given I start the app with the arguments "m.php"
    When I click on the text "⇥ HEAD"
    Then the editor text should be:
      """
      <?php
      $a = 'ours';
      $b = 1;
      """
    And I should see "No conflicts left — save the result"
    When I focus "#editor"
    And I press "Ctrl+Z"
    Then the editor should contain "<<<<<<< HEAD"

  Scenario: Resolved by hand, the result is saved like any file
    Given I start the app with the arguments "m.php"
    When I click on the text "⇤ topic"
    And I press "Ctrl+S"
    Then the file "m.php" should contain exactly:
      """
      <?php
      $a = 'theirs';
      $b = 1;
      """
