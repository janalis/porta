Feature: Debugging PHP with the xdebug plugin
  The xdebug plugin supplies PHP's debug adapter, vscode-php-debug run with
  node, so Shift+F9 debugs with nothing configured. Its two settings name the
  adapter script and what runs it; here they name the scripted adapter, run
  by this PHP, so the session that starts proves which program ran.

  Background:
    Given a PHP project
    And a file "prog.php" containing:
      """
      <?php
      // l2
      // l3
      // l4
      """

  @setting:xdebug.adapterPath @setting:xdebug.nodeBinary
  Scenario: The adapter script and the binary that runs it come from the plugin's settings
    Given the bundled plugins are available
    And the xdebug plugin runs the scripted debug adapter
    And I start the app with the arguments "prog.php --plugin=xdebug"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:3"
    And I should see "$x = 42"

  Scenario: An adapter configured for the language wins over the plugin's
    Given the bundled plugins are available
    And the project configuration:
      """
      {"plugins": {"xdebug": {"nodeBinary": "/nonexistent/node", "adapterPath": "/nonexistent/phpDebug.js"}}}
      """
    And the scripted debug adapter is configured for PHP
    And I start the app with the arguments "prog.php --plugin=xdebug"
    When I put the caret on line 3
    And I use the action "debug_toggle_breakpoint"
    And I use the action "ide_debug"
    Then I should see "paused at prog.php:3"

  @command:xdebug:listen
  Scenario: Listening starts the adapter with nothing to run
    Given the bundled plugins are available
    And the xdebug plugin runs the scripted debug adapter
    And I start the app with the arguments "prog.php --plugin=xdebug"
    When I find the action "Xdebug: Start Listening for Connections"
    And I press "Enter"
    Then I should see "Listening (php)"

  @command:xdebug:install
  Scenario: Installing the adapter says why it could not
    Given the environment variable "PATH" is "/nonexistent"
    And the environment variable "PORTA_HOME" is "%workspace%/home"
    And the bundled plugins are available
    And I start the app with the arguments "prog.php --plugin=xdebug"
    When I find the action "Xdebug: Install the php-debug Adapter"
    And I press "Enter"
    Then I should see "curl is not available"
    And the file "home/.porta/tools/php-debug/out/phpDebug.js" should not exist
