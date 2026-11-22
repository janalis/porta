Feature: Local reviews
  An agent leaves a review as files under .porta/reviews/: a thread per
  directory, a comment per file. The Review view lists the threads by file
  beside the code they are about, and a reply or a new thread lands as a new
  file, never as an edit of the agent's. In the editor the thread's line is
  marked, and its first comment or whole conversation can be shown there too.

  Background:
    Given a PHP project
    And the bundled plugins "review" are enabled
    And the project configuration:
      """
      {"plugins": {"review": {"provider": "local"}}}
      """
    And a file "src/A.php" containing:
      """
      <?php
      $total = 0;
      foreach ($items as $item) {
          $total += $item;
      }
      """
    And a file ".porta/reviews/agent/review.json" containing:
      """
      {"title": "Tidy the loop"}
      """
    And a file ".porta/reviews/agent/t1/thread.json" containing:
      """
      {"path": "src/A.php", "line": 4, "lineText": "    $total += $item;"}
      """
    And a file ".porta/reviews/agent/t1/20260929T100000Z--claude.md" containing "Use array_sum here."

  Scenario: The Review view lists the agent's threads under their file
    Given I start the app in a 120x40 terminal with the arguments "src/A.php"
    When I use the action "review:open"
    Then I should see "Tidy the loop"
    And I should see "Review (1 open)"
    And I should see "✎ L4 claude: Use array_sum here."
    And I should see "✎ agent · 1"

  Scenario: Re-reading the review brings in what the agent wrote since
    Given I start the app in a 120x40 terminal with the arguments "src/A.php"
    And I use the action "review:open"
    And I should see "Review (1 open)"
    When a file ".porta/reviews/agent/t2/thread.json" containing:
      """
      {"path": "src/A.php", "line": 2, "lineText": "$total = 0;"}
      """
    And a file ".porta/reviews/agent/t2/20260929T100100Z--claude.md" containing "Start at 1?"
    And I use the action "review:refresh"
    Then I should see "✎ L2 claude: Start at 1?"
    And I should see "Review (2 open)"

  Scenario: Another review on disk is chosen instead of the newest
    Given a file ".porta/reviews/other/t9/thread.json" containing:
      """
      {"path": "src/A.php", "line": 1}
      """
    And a file ".porta/reviews/other/t9/1--bot.md" containing "Header?"
    And I start the app in a 120x40 terminal with the arguments "src/A.php"
    When I use the action "review:choose"
    Then I should see "Show which review?"
    And I should see "Local review: agent"
    When I type "Local review: other"
    And I press "Enter"
    And I use the action "review:open"
    Then I should see "✎ L1 bot: Header?"
    And I should not see "Use array_sum here."

  Scenario: Comment on This Line starts a thread on the caret's line
    Given I start the app in a 120x40 terminal with the arguments "src/A.php"
    And I should see "✎ agent · 1"
    When I put the caret on line 2
    And I use the action "review:comment_line"
    Then I should see "New comment on src/A.php:2"
    When I type "Start at 1?"
    And I press "Ctrl+Enter"
    Then I should see "✎ L2 "
    And the review should have a thread on "src/A.php" line 2 reading "$total = 0;"

  Scenario: Show Conversation on This Line opens the view on that thread
    Given I start the app in a 120x40 terminal with the arguments "src/A.php"
    And I should see "✎ agent · 1"
    When I put the caret on line 4
    And I use the action "review:show_conversation"
    Then I should see "Tidy the loop"
    And I should see "Use array_sum here." 2 times

  Scenario: Show Conversation where there is none says so
    Given I start the app with the arguments "src/A.php"
    And I should see "✎ agent · 1"
    When I put the caret on line 2
    And I use the action "review:show_conversation"
    Then a toast should say "No conversation on this line"

  Scenario: A local review has no link to copy
    Given I start the app with the arguments "src/A.php"
    And I should see "✎ agent · 1"
    When I use the action "review:copy_link"
    Then a toast should say "This review has no link"
    And nothing should have been copied to the clipboard

  @setting:review.editorDisplay
  Scenario Outline: How much of a thread the editor shows
    Given the project configuration:
      """
      {"plugins": {"review": {"editorDisplay": "<display>"}}}
      """
    When I start the app with the arguments "src/A.php"
    Then I should see "✎ agent · 1"
    And the editor's review marks should be <marks>
    And I should <summary> "claude: Use array_sum here."

    Examples:
      | display | marks                               | summary |
      | off     | empty                               | not see |
      | gutter  | on line 4                           | not see |
      | summary | on line 4                           | see     |
      | inline  | on line 4                           | see     |

  @setting:review.showResolved
  Scenario: Resolved threads are hidden unless asked for
    Given an empty file ".porta/reviews/agent/t1/resolved"
    And a file ".porta/reviews/agent/t0/thread.json" containing:
      """
      {"path": "src/A.php", "line": 2, "lineText": "$total = 0;"}
      """
    And a file ".porta/reviews/agent/t0/1--claude.md" containing "Start at 1?"
    And I start the app in a 120x40 terminal with the arguments "src/A.php"
    When I use the action "review:open"
    Then I should see "Start at 1?"
    And I should not see "Use array_sum here."
    When I press "s"
    Then I should see "Use array_sum here."
    And the "review" plugin setting "showResolved" should be "true"
