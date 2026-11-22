Feature: The language server's log
  What the servers write to their log channel is kept, and the LSP log shows
  it in a dock tab, newest first.

  Scenario: The log shows what the server said
    Given a PHP project
    And the mock language server is configured
    And a file "src/A.php" containing "<?php"
    And a file "src/B.php" containing "<?php"
    And I start the app with the arguments "src/A.php"
    And the language server is ready
    When I use the action "goto_file"
    And I type "B.php"
    And I should see "B.php" in the dialog "Go to File"
    And I press "Enter"
    And the editor should show "src/B.php"
    And I use the action "close_buffer"
    And the editor should show "src/A.php"
    And I use the action "ide_lsp_log"
    Then I should see "LSP Server Log"
    And I should see "[mock-lsp-server] didClose file://"
