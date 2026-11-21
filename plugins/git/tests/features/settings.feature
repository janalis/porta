Feature: Git settings
  The git plugin's page under Settings → Plugins: the branch cell, how a
  commit is made (signed, hooks skipped, signed off), how often the open
  view re-reads the status, which git runs, where new worktrees go, and the
  diff pane's two display toggles the view keeps itself.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And a file ".gitignore" containing:
      """
      .porta/
      .porta.json
      *.log
      tools/
      wt/
      """
    And a file "a.php" containing:
      """
      <?php
      echo 'one';
      """
    And a git repository with everything committed
    And git commits here as "Ada"

  Scenario: The status bar shows the current branch
    When I start the app with the arguments "a.php"
    Then I should see "main ▾"

  @setting:git.showBranch
  Scenario: The branch cell can be hidden
    Given the project configuration:
      """
      {"plugins": {"git": {"showBranch": false}}}
      """
    And I start the app in a 120x45 terminal with the arguments "a.php"
    # The view's toolbar names the branch once git answered; the status bar must not
    When I use the action "ide_git"
    And I should see "Working tree clean"
    And I press "Escape"
    Then I should not see "Current repository"
    And I should not see "main ▾"

  Scenario: A commit hook can refuse a commit
    Given a stand-in ".git/hooks/pre-commit" running:
      """
      #!/bin/sh
      exit 1
      """
    And a file "a.php" containing "<?php echo 'two';"
    And I start the app with the arguments "a.php"
    When I use the action "git_stage"
    And git "status --porcelain" should print "M  a.php"
    And I use the action "git_commit"
    And I type "Blocked"
    And I press "Enter"
    Then a toast should say "Commit failed"
    And git "log --format=%s" should print exactly:
      """
      initial
      """

  @setting:git.noVerify
  Scenario: Commit hooks can be skipped
    Given a stand-in ".git/hooks/pre-commit" running:
      """
      #!/bin/sh
      exit 1
      """
    And the project configuration:
      """
      {"plugins": {"git": {"noVerify": true}}}
      """
    And a file "a.php" containing "<?php echo 'two';"
    And I start the app with the arguments "a.php"
    When I use the action "git_stage"
    And git "status --porcelain" should print "M  a.php"
    And I use the action "git_commit"
    And I type "Past the hook"
    And I press "Enter"
    Then git "log -1 --format=%s" should print "Past the hook"

  @setting:git.signoff
  Scenario: Commits can carry a Signed-off-by trailer
    Given the project configuration:
      """
      {"plugins": {"git": {"signoff": true}}}
      """
    And a file "a.php" containing "<?php echo 'two';"
    And I start the app with the arguments "a.php"
    When I use the action "git_stage"
    And git "status --porcelain" should print "M  a.php"
    And I use the action "git_commit"
    And I type "Signed off"
    And I press "Enter"
    Then git "log -1 --format=%B" should print "Signed-off-by: Ada <ada@example.invalid>"

  @setting:git.signCommits
  Scenario: Commits can be signed
    Given a stand-in "tools/gpg" running:
      """
      #!/bin/sh
      echo "asked to sign" >> %workspace%/gpg.log
      exit 1
      """
    And git "config gpg.program tools/gpg" was run
    And the project configuration:
      """
      {"plugins": {"git": {"signCommits": true}}}
      """
    And a file "a.php" containing "<?php echo 'two';"
    And I start the app with the arguments "a.php"
    When I use the action "git_stage"
    And git "status --porcelain" should print "M  a.php"
    And I use the action "git_commit"
    And I type "Signed"
    And I press "Enter"
    # The stand-in refuses: what matters is that git asked it to sign
    Then the file "gpg.log" should contain "asked to sign"
    And a toast should say "Commit failed"

  @setting:git.path
  Scenario: Another git executable can be named
    Given a stand-in "tools/git" running:
      """
      #!/bin/sh
      echo "$*" >> %workspace%/calls.log
      exec git "$@"
      """
    And the "git" plugin setting "path" is the workspace path "tools/git"
    When I start the app with the arguments "a.php"
    And I use the action "git_blame"
    Then a toast should say "Behat: initial"
    And the file "calls.log" should contain "blame"

  @setting:git.refreshInterval
  Scenario: The open view re-reads the status every so often
    Given the project configuration:
      """
      {"plugins": {"git": {"refreshInterval": 1}}}
      """
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "ide_git"
    And I should see "Working tree clean"
    And a file "b.php" containing "<?php"
    Then I should see "[ ] b.php"

  @setting:git.refreshInterval
  Scenario: A long refresh interval leaves the open view as it was
    Given the project configuration:
      """
      {"plugins": {"git": {"refreshInterval": 3600}}}
      """
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "ide_git"
    And I should see "Working tree clean"
    And a file "b.php" containing "<?php"
    # Past the default 2 s poll, by the real clock the poll runs on
    And I wait 3 seconds
    Then I should not see "[ ] b.php"
    And I should see "Working tree clean"

  @setting:git.diffSplit
  Scenario: The diff is shown side by side, and s switches it back
    Given a file "a.php" containing:
      """
      <?php
      echo 'two';
      """
    And the project configuration:
      """
      {"plugins": {"git": {"diffSplit": true}}}
      """
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "ide_git"
    Then I should see text matching "/-echo 'one';\s+│\s+2 \+echo 'two';/"
    When I press "Tab"
    And I press "s"
    Then I should see "-echo 'one';"
    And I should not see text matching "/-echo 'one';.*echo 'two';/"
    And the "git" plugin setting "diffSplit" should be "false"

  @setting:git.diffHideWhitespace
  Scenario: Whitespace-only changes can be hidden, and w shows them again
    Given a file "a.php" containing:
      """
      <?php
          echo 'one';
      """
    And the project configuration:
      """
      {"plugins": {"git": {"diffHideWhitespace": true}}}
      """
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "ide_git"
    Then I should see "No changes (whitespace hidden)"
    When I press "Tab"
    And I press "w"
    Then I should see "+    echo 'one';"
    And the "git" plugin setting "diffHideWhitespace" should be "false"

  @setting:git.worktreeFolder
  Scenario: New worktrees go in the folder named for them
    Given the project configuration:
      """
      {"plugins": {"git": {"worktreeFolder": "wt"}}}
      """
    And I start the app with the arguments "a.php"
    When I find the action "Git: New Worktree"
    And I press "Enter"
    And I should see "Worktree name"
    And I type "feat"
    And I press "Enter"
    Then I should see "+ New branch feat"
    And I should see "wt/feat"
    When I press "Enter"
    Then I should see "Open feat?"
    When I press "Down"
    And I press "Enter"
    Then the file "wt/feat/a.php" should exist
    And git "worktree list" should print "[feat]"
