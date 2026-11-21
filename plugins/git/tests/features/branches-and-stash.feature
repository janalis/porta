Feature: Branches and the stash
  Git: Branches… lists the local branches, the current one checked, and
  creates or switches to one. Git: Stash shelves the working tree and
  Stash Pop puts the newest shelf back; Git: Stashes… picks any one to
  apply, pop or drop.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And a file ".gitignore" containing:
      """
      .porta/
      .porta.json
      """
    And a file "a.php" containing "<?php echo 'one';"
    And a git repository with everything committed
    And git commits here as "Ada"

  Scenario: Picking a branch checks it out
    Given git "branch feature" was run
    And I start the app with the arguments "a.php"
    When I use the action "git_branches"
    Then I should see "Branches"
    And I should see "+ Create new branch…"
    And I should see "feature"
    When I type "feature"
    And I press "Enter"
    Then git "branch --show-current" should print "feature"
    And I should see "feature ▾"

  Scenario: A new branch is created and switched to
    Given I start the app with the arguments "a.php"
    When I use the action "git_branches"
    And I should see "+ Create new branch…"
    And I press "Enter"
    Then I should see "New branch name"
    When I type "topic"
    And I press "Enter"
    Then git "branch --show-current" should print "topic"

  Scenario: Stashing shelves the changes, and Stash Pop puts them back
    Given a file "a.php" containing "<?php echo 'two';"
    And I start the app with the arguments "a.php"
    When I use the action "git_stash"
    Then the file "a.php" should contain "one"
    And git "stash list" should print "stash@{0}"
    When I use the action "git_stash_pop"
    Then the file "a.php" should contain "two"
    And git "stash list" should print no output
    And a toast should say "Stash popped"

  Scenario: A stash is picked from the list and dropped
    Given a file "a.php" containing "<?php echo 'two';"
    And git "stash push -q -m shelved" was run
    And I start the app with the arguments "a.php"
    When I use the action "git_stashes"
    Then I should see "Stashes"
    And I should see "shelved"
    When I press "Enter"
    Then I should see "Apply"
    And I should see "Drop"
    When I type "Drop"
    And I press "Enter"
    Then git "stash list" should print no output
    And the file "a.php" should contain "one"

  Scenario: A stash is picked from the list and applied, keeping it
    Given a file "a.php" containing "<?php echo 'two';"
    And git "stash push -q -m shelved" was run
    And I start the app with the arguments "a.php"
    When I use the action "git_stashes"
    And I should see "shelved"
    And I press "Enter"
    And I should see "Apply"
    And I press "Enter"
    Then the file "a.php" should contain "two"
    And git "stash list" should print "shelved"
