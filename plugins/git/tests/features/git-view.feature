Feature: The Git view
  Ctrl+Alt+D opens a GitHub-Desktop-style window over the editor: the
  changed files with their checkboxes, the selected file's diff, a commit box
  and the History tab. The palette has the same verbs for the open file:
  stage it, commit with a message, undo the last commit, or browse the
  commits that touched it.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And a file ".gitignore" containing:
      """
      .porta/
      .porta.json
      *.log
      """
    And a file "a.php" containing:
      """
      <?php
      echo 'one';
      """
    And a git repository with everything committed
    And git commits here as "Ada"

  Scenario: On a 32-row terminal the window is tall enough to list the changes
    Given a file "a.php" containing:
      """
      <?php
      echo 'two';
      """
    And a file "b.php" containing "<?php"
    When I start the app in a 120x32 terminal
    And I use the action "ide_git"
    Then I should see "2 changed files"
    And I should see "a.php" in the dialog "Git"
    And I should see "b.php" in the dialog "Git"
    And I should see "Commit to main"

  Scenario: The view lists what changed and shows its diff; Esc closes it
    Given a file "a.php" containing:
      """
      <?php
      echo 'two';
      """
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "ide_git"
    Then I should see "1 changed file"
    And I should see "[ ] a.php"
    And I should see "-echo 'one';"
    And I should see "+echo 'two';"
    When I press "Escape"
    Then I should not see "Current repository"
    And the element "#editor" should be focused

  Scenario: Space stages the file, and Ctrl+Enter commits it with the summary typed
    Given a file "a.php" containing:
      """
      <?php
      echo 'two';
      """
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "ide_git"
    And I should see "[ ] a.php"
    And I press "Space"
    Then git "status --porcelain" should print "M  a.php"
    And I should see "[✓] a.php"
    When I press "Tab"
    And I press "Tab"
    And I type "Say two"
    And I press "Ctrl+Enter"
    Then git "log --format=%s" should print exactly:
      """
      Say two
      initial
      """
    And I should see "Working tree clean"

  Scenario: The open file is staged from the palette
    Given a file "a.php" containing:
      """
      <?php
      echo 'two';
      """
    And I start the app with the arguments "a.php"
    When I use the action "git_stage"
    Then git "status --porcelain" should print "M  a.php"
    And a toast should say "Staged a.php"

  Scenario: A commit asks for its message
    Given a file "a.php" containing:
      """
      <?php
      echo 'two';
      """
    And I start the app with the arguments "a.php"
    When I use the action "git_stage"
    And git "status --porcelain" should print "M  a.php"
    And I use the action "git_commit"
    Then I should see "Commit message"
    When I type "From the palette"
    And I press "Enter"
    Then git "log -1 --format=%s%n%an" should print exactly:
      """
      From the palette
      Ada
      """
    And a toast should say "Committed: From the palette"

  Scenario: Undoing the last commit keeps its changes staged
    Given a file "a.php" containing:
      """
      <?php
      echo 'two';
      """
    And I commit everything as "Say two"
    And I start the app with the arguments "a.php"
    When I use the action "git_undo_commit"
    Then git "log --format=%s" should print exactly:
      """
      initial
      """
    And git "status --porcelain" should print "M  a.php"
    And a toast should say "Last commit undone (changes kept staged)"

  Scenario: The History tab lists the commits and what each changed
    Given a file "b.php" containing "<?php"
    And I commit everything as "Add b"
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "ide_git"
    And I press "2"
    Then I should see "Add b"
    And I should see "initial"
    And I should see "1 changed file"
    And I should see "b.php"

  Scenario: File History shows only the commits that touched the open file
    Given a file "b.php" containing "<?php"
    And I commit everything as "Add b"
    And a file "a.php" containing:
      """
      <?php
      echo 'two';
      """
    And I commit everything as "Say two"
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "git_file_history"
    Then I should see "Say two"
    And I should see "initial"
    And I should not see "Add b"

  Scenario: Two files picked in the project tree are compared in a panel
    Given a file "b.php" containing:
      """
      <?php
      echo 'other';
      """
    # No file open: each name shows once on the screen, in the tree
    And I start the app
    When I click on the text "a.php"
    And I click on the text "b.php" with "Ctrl"
    And I use the action "git_compare_files"
    Then I should see "a.php ↔ b.php"
    And I should see "-echo 'one';"
    And I should see "+echo 'other';"
