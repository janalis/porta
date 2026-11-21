Feature: A project on a server, over ssh
  File → Open Remote Project… opens a project that lives on a server and
  edits it as though it were here: the tree lists the server's files, a file
  is fetched when opened, and a save is sent back. The ssh plugin runs the
  ssh client its setting names — here the suite's stand-in client, which
  reaches a stand-in server on this machine.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And Porta's home is in the workspace
    And a file "server/README.md" containing "# served"
    And a file "server/src/App.php" containing "<?php // on the server"
    And an ssh server serving "server"

  @setting:ssh.binary
  Scenario: A project opened over ssh is listed, fetched and saved back
    Given Porta's global configuration:
      """
      {"plugins": {"ssh": {"binary": "%ssh-client%"}}}
      """
    And I start the app
    When I use the action "ide_open_remote_project"
    Then I should see "Open remote project (user@host:/path or URL)"
    When I type the address "ssh://ada@127.0.0.1:%port%%root%"
    And I press "Enter"
    And Porta reopens on the project it was asked for
    Then I should see "README.md"
    And I should see "⇅ 127.0.0.1"
    When I find the action "Go to File"
    And I press "Enter"
    And I type "README"
    And I press "Enter"
    Then the editor should contain "# served"
    When I type "X"
    And I press "Ctrl+S"
    Then the file "server/README.md" should contain "X# served"

  @setting:ssh.binary
  Scenario: The server is lost while the ssh client named cannot be run
    Given Porta's global configuration:
      """
      {"plugins": {"ssh": {"binary": "%workspace%/no-such-ssh"}}}
      """
    And I start the app
    When I use the action "ide_open_remote_project"
    And I type the address "ssh://ada@127.0.0.1:%port%%root%"
    And I press "Enter"
    And Porta reopens on the project it was asked for
    Then I should see "⇅ 127.0.0.1 ⌁"
    And a toast should say "no-such-ssh: command not found"
    And I should not see "README.md"
