Feature: Porta as git's difftool and mergetool
  `git difftool` runs `porta --diff LOCAL REMOTE`, and `git mergetool` runs
  `porta --merge LOCAL REMOTE BASE MERGED`. The comparison opens in the Git
  view, titled with the real file, and closing it ends the run git waits on;
  a merge opens the conflicted file in the merge view.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And a file ".gitignore" containing:
      """
      .porta/
      .porta.json
      tmp/
      """

  Scenario: A difftool run shows the two files, and Esc ends it
    Given a file "a.php" containing:
      """
      <?php
      echo 'working tree';
      """
    And a git repository with everything committed
    # Git hands the old side over as a temporary copy
    And a file "tmp/a_LOCAL_123.php" containing:
      """
      <?php
      echo 'committed';
      """
    When I start the app with the arguments "--diff tmp/a_LOCAL_123.php a.php"
    Then I should see "-echo 'committed';"
    And I should see "+echo 'working tree';"
    And I should see "Git"
    When I press "Escape"
    Then the app should have exited

  Scenario: A mergetool run opens the conflicted file in the merge view
    Given a file "a.php" containing:
      """
      <?php
      echo 'base';
      """
    And a git repository with everything committed
    And git "switch -q -c topic" was run
    And a file "a.php" containing:
      """
      <?php
      echo 'theirs';
      """
    And I commit everything as "theirs"
    And git "switch -q main" was run
    And a file "a.php" containing:
      """
      <?php
      echo 'ours';
      """
    And I commit everything as "ours"
    And git "merge topic" fails
    When I start the app with the arguments "--merge tmp/a_LOCAL.php tmp/a_REMOTE.php tmp/a_BASE.php a.php"
    # Ours on the left, labelled by the markers git wrote; the hint says how to finish
    Then I should see "⇥ HEAD"
    And the editor should contain "<<<<<<< HEAD"
    And a toast should say "a keeps a side"
    When I click on the text "⇥ HEAD"
    Then the editor text should be:
      """
      <?php
      echo 'ours';
      """
    When I press "Ctrl+S"
    Then the file "a.php" should contain exactly:
      """
      <?php
      echo 'ours';
      """
    When I press "Ctrl+Q"
    Then the app should have exited
