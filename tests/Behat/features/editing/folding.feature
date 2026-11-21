Feature: Code folding
  Ctrl+. folds the block at the caret behind a "⋯ N lines" marker; Fold All
  collapses every region in the file and Unfold All opens them again.

  Background:
    Given a PHP project
    And a file "A.php" containing:
      """
      <?php

      class A
      {
          public function one(): void
          {
              $first = 1;
          }

          public function two(): void
          {
              $second = 2;
          }
      }
      """
    And I start the app with the arguments "A.php"

  Scenario: Fold and unfold the block at the caret
    Given the caret is at line 6, column 5
    When I use the action "toggle_fold"
    Then I should not see "$first = 1;"
    And I should see "$second = 2;"
    And I should see "⋯"
    When I use the action "toggle_fold"
    Then I should see "$first = 1;"

  # Ctrl+Shift+- reaches the terminal as Ctrl+_ from tuink (it drops the
  # Shift), so Fold All is run by its name here; Unfold All has its key.
  @action:fold_all
  Scenario: Fold every region, then open them all
    When I find the action "Fold All"
    And I press "Enter"
    Then I should not see "$first = 1;"
    And I should not see "$second = 2;"
    When I use the action "unfold_all"
    Then I should see "$first = 1;"
    And I should see "$second = 2;"
