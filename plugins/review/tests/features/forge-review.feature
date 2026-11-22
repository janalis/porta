Feature: Pull and merge request reviews
  Without a local review, the review of the current branch is the open pull
  or merge request on the forge its remote lives on, read through the forge's
  own CLI — here a stand-in that answers as `gh` or `glab` would, so nothing
  reaches the network. github.com and gitlab.com are known by name; any other
  host is named in a setting.

  Background:
    Given a PHP project
    And the bundled plugins "review" are enabled
    And a file ".gitignore" containing:
      """
      .porta/
      .porta.json
      .forge/
      """
    And a file "src/A.php" containing:
      """
      <?php
      $a = 1;
      $bee = 2;
      """
    And a git repository with everything committed

  @setting:review.ghBinary
  Scenario: A GitHub pull request is read through gh, and its link copied
    Given git "remote add origin git@github.com:acme/app.git" was run
    And the review plugin runs the forge stand-in as "ghBinary"
    And GitHub has the pull request "Tidy the names" for this branch, with the threads:
      | author | line | file      | comment  |
      | bob    | 3    | src/A.php | Why bee? |
    When I start the app with the arguments "src/A.php"
    Then I should see "✎ PR #12 · 1"
    And the editor's review marks should be on line 3
    When I use the action "review:copy_link"
    Then the clipboard should contain "https://github.com/acme/app/pull/12"

  @setting:review.glabBinary
  Scenario: A GitLab merge request is read through glab
    Given git "remote add origin git@gitlab.com:acme/app.git" was run
    And the review plugin runs the forge stand-in as "glabBinary"
    And GitLab has a merge request for this branch
    When I start the app in a 120x40 terminal with the arguments "src/A.php"
    Then I should see "✎ MR !7"
    When I use the action "review:open"
    Then I should see "Tidy the dumper"

  @setting:review.hosts
  Scenario: A self-hosted forge is named for what it runs
    Given git "remote add origin git@git.corp.example:acme/app.git" was run
    And the review plugin runs the forge stand-in as "ghBinary"
    And the project configuration:
      """
      {"plugins": {"review": {"hosts": "git.corp.example=github"}}}
      """
    And GitHub has the pull request "Tidy the names" for this branch, with the threads:
      | author | line | file      | comment  |
      | bob    | 3    | src/A.php | Why bee? |
    When I start the app with the arguments "src/A.php"
    Then I should see "✎ PR #12 · 1"

  @setting:review.provider
  Scenario: The provider setting pins the forge over a local review on disk
    Given git "remote add origin git@github.com:acme/app.git" was run
    And the review plugin runs the forge stand-in as "ghBinary"
    And GitHub has the pull request "Tidy the names" for this branch, with the threads:
      | author | line | file      | comment  |
      | bob    | 3    | src/A.php | Why bee? |
    And a file ".porta/reviews/agent/t1/thread.json" containing:
      """
      {"path": "src/A.php", "line": 2, "lineText": "$a = 1;"}
      """
    And a file ".porta/reviews/agent/t1/1--claude.md" containing "Local, and newer"
    And the project configuration:
      """
      {"plugins": {"review": {"provider": "github"}}}
      """
    When I start the app with the arguments "src/A.php"
    Then I should see "✎ PR #12 · 1"
    And the editor's review marks should be on line 3

  Scenario: Without a provider pinned, the local review on disk comes first
    Given git "remote add origin git@github.com:acme/app.git" was run
    And the review plugin runs the forge stand-in as "ghBinary"
    And GitHub has the pull request "Tidy the names" for this branch, with the threads:
      | author | line | file      | comment  |
      | bob    | 3    | src/A.php | Why bee? |
    And a file ".porta/reviews/agent/t1/thread.json" containing:
      """
      {"path": "src/A.php", "line": 2, "lineText": "$a = 1;"}
      """
    And a file ".porta/reviews/agent/t1/1--claude.md" containing "Local, and newer"
    When I start the app with the arguments "src/A.php"
    Then I should see "✎ agent · 1"
    And the editor's review marks should be on line 2

  @setting:review.refreshSeconds
  Scenario: A forge review is re-read every so often
    Given git "remote add origin git@github.com:acme/app.git" was run
    And the review plugin runs the forge stand-in as "ghBinary"
    And the project configuration:
      """
      {"plugins": {"review": {"refreshSeconds": 1}}}
      """
    And GitHub has the pull request "Tidy the names" for this branch, with the threads:
      | author | line | file      | comment  |
      | bob    | 3    | src/A.php | Why bee? |
    When I start the app with the arguments "src/A.php"
    Then I should see "✎ PR #12 · 1"
    And the forge stand-in should have been asked for the review 3 times or more
