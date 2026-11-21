Feature: Fetch, pull and push
  Git: Fetch, Pull and Push run in the background against the repository's
  remote — here a bare repository kept in the workspace. A branch with no
  upstream is offered --set-upstream rather than failing, and a remote
  operation that hangs can be cancelled.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And a file ".gitignore" containing:
      """
      .porta/
      .porta.json
      .remote/
      """
    And a file "a.php" containing "<?php"
    And a git repository with everything committed
    And git commits here as "Ada"
    And a bare remote "origin" the branch is pushed to
    # git as configured out of the box: a user's push.default must not decide what a push does
    And the environment variable "GIT_CONFIG_GLOBAL" is "/dev/null"

  Scenario: Fetching brings the remote's commits in without touching the branch
    Given someone else pushes "theirs.txt" to "origin" as "Their commit"
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "git_fetch"
    Then git "log -1 --format=%s origin/main" should print "Their commit"
    And git "log -1 --format=%s main" should print "initial"
    When I use the action "ide_git"
    Then I should see "⇣1"

  Scenario: Pulling brings the remote's commits into the branch
    Given someone else pushes "theirs.txt" to "origin" as "Their commit"
    And I start the app with the arguments "a.php"
    When I use the action "git_pull"
    Then git "log -1 --format=%s main" should print "Their commit"
    And the file "theirs.txt" should exist

  Scenario: Pushing sends the branch's commits to the remote
    Given a file "b.php" containing "<?php"
    And I commit everything as "Mine"
    And I start the app with the arguments "a.php"
    When I use the action "git_push"
    Then git "--git-dir=.remote/origin.git log -1 --format=%s main" should print "Mine"

  Scenario: A branch with no upstream is offered --set-upstream
    Given git "switch -q -c topic" was run
    And I start the app with the arguments "a.php"
    When I use the action "git_push"
    Then I should see "Push with --set-upstream origin topic"
    When I press "Enter"
    Then git "--git-dir=.remote/origin.git branch --list topic" should print "topic"
    And git "rev-parse --abbrev-ref topic@{upstream}" should print "origin/topic"

  Scenario: Push (Set Upstream) publishes the branch directly
    Given git "switch -q -c topic" was run
    And I start the app with the arguments "a.php"
    When I use the action "git_push_upstream"
    Then git "--git-dir=.remote/origin.git branch --list topic" should print "topic"
    And git "rev-parse --abbrev-ref topic@{upstream}" should print "origin/topic"

  Scenario: A remote operation that hangs is cancelled
    Given the remote "origin" is slow to answer
    And I start the app in a 120x45 terminal with the arguments "a.php"
    When I use the action "git_fetch"
    And I use the action "ide_git"
    Then I should see "Fetching origin…"
    When I use the action "git_remote_cancel"
    Then a toast should say "Git remote operation cancelled"
    And I should not see "Fetching origin…"

  Scenario: Cancelling with nothing running says so
    Given I start the app with the arguments "a.php"
    When I use the action "git_remote_cancel"
    Then a toast should say "No git remote operation is running"
