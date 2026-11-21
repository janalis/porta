Feature: A project on a server, over FTP
  An ftp:// address opens the server's files the same way: listed in the
  tree, fetched on opening, sent back on saving. The ftp plugin talks to the
  server through curl, the one its setting names — here a wrapper that notes
  each call before handing it to the real curl, against a small FTP server
  on this machine.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And Porta's home is in the workspace
    And a file "server/README.md" containing "# served"
    And an FTP server serving "server"

  @setting:ftp.binary
  Scenario: A project opened over FTP goes through the curl the setting names
    Given a stand-in "tools/curl" running:
      """
      #!/bin/sh
      echo "$*" >> %workspace%/curl.log
      exec curl "$@"
      """
    And Porta's global configuration:
      """
      {"plugins": {"ftp": {"binary": "%workspace%/tools/curl"}}}
      """
    And I start the app
    When I use the action "ide_open_remote_project"
    And I type the address "ftp://127.0.0.1:%port%/"
    And I press "Enter"
    And Porta reopens on the project it was asked for
    Then I should see "README.md"
    And the file "curl.log" should contain "ftp://127.0.0.1"
