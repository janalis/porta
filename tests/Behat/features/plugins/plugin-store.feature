Feature: The plugin store
  Settings › Plugins is the store: every Porta plugin published on Packagist,
  in four tabs — Discover, Categories, Updates and Installed — each plugin
  with its page. It opens on what it last fetched, kept under
  ~/.porta/cache/store. In the background Porta asks for newer releases of
  the plugins installed from Packagist, at the pluginUpdateCheck cadence, and
  with pluginAutoUpdate on installs them.

  The network here is a stand-in: an address the scenario gives answers, any
  other fails.

  Background:
    Given a PHP project
    And a Porta home of my own
    And a file "a.php" containing "<?php"
    And the store lists:
      | package           | title       | description          | category | version | readme                                           | featured |
      | acme/porta-hello  | Hello World | Says hello in a toast | other    | 1.1.0   | https://raw.githubusercontent.com/acme/hello.md | yes      |
      | acme/porta-lintly | Lintly      | Lints what you write  | linters  | 2.0.0   |                                                  | no       |

  Scenario: Plugins: Open Store opens the store on Discover
    Given I start the app with the arguments "a.php"
    When I use the action "plugins_store"
    Then I should see "Settings › Plugins"
    And I should see "Discover"
    And I should see "Hello World"
    And I should see "Lintly"

  Scenario: The Categories tab counts each category's plugins, and opens one
    Given I start the app with the arguments "a.php"
    When I use the action "plugins_store"
    And I focus "#settings-store"
    And I press "]"
    Then I should see text matching "/Linters & formatters\s+1 ›/"
    When I click on the text "Linters & formatters"
    And I press "Enter"
    Then I should see "Lintly"
    And I should not see "Hello World"

  Scenario: A plugin's page shows what it is, its README among it
    Given the address "https://raw.githubusercontent.com/acme/hello.md" answers:
      """
      # Hello World

      Greets you from a toast, every morning.
      """
    And I start the app with the arguments "a.php"
    When I use the action "plugins_store"
    And I focus "#settings-store"
    And I click on the text "Hello World"
    And I press "Enter"
    Then I should see "Greets you from a toast, every morning."
    And I should see "acme/porta-hello"

  Scenario: Updates lists an installed plugin the store has a newer release of
    Given the plugin "acme/porta-hello" 1.0.0 is installed from Packagist in this project:
      """
      <?php
      return new class implements Porta\Plugin\Api\PortaPlugin {
          public function name(): string { return 'hello'; }
          public function activate(Porta\Plugin\Api\PluginApi $api): void {}
      };
      """
    And I start the app with the arguments "a.php"
    When I use the action "plugins_store"
    Then I should see "Plugins (1)"
    When I focus "#settings-store"
    And I press "u"
    Then I should see "Update all"
    And I should see "Hello World"
    And I should not see "Lintly"

  @setting:pluginUpdateCheck
  Scenario: A due check asks Packagist, and says once what could be updated
    Given the plugin "acme/porta-hello" 1.0.0 is installed from Packagist in this project:
      """
      <?php
      return new class implements Porta\Plugin\Api\PortaPlugin {
          public function name(): string { return 'hello'; }
          public function activate(Porta\Plugin\Api\PluginApi $api): void {}
      };
      """
    And the store was last refreshed long ago
    And Packagist publishes "acme/porta-hello" at "1.2.0, 1.0.0"
    When I start the app with the arguments "a.php"
    Then a notification should say "Plugin updates available: hello 1.2.0"
    And "https://repo.packagist.org/p2/acme/porta-hello.json" should have been asked for

  @setting:pluginUpdateCheck
  Scenario: With the check off, nothing is asked until it is switched on
    Given the plugin "acme/porta-hello" 1.0.0 is installed from Packagist in this project:
      """
      <?php
      return new class implements Porta\Plugin\Api\PortaPlugin {
          public function name(): string { return 'hello'; }
          public function activate(Porta\Plugin\Api\PluginApi $api): void {}
      };
      """
    And the store was last refreshed long ago
    And Packagist publishes "acme/porta-hello" at "1.2.0, 1.0.0"
    And the project configuration:
      """
      {"settings": {"pluginUpdateCheck": "off"}}
      """
    And I start the app with the arguments "a.php"
    When I open the Settings page "General"
    Then nothing should have been asked of the network
    When I choose "daily" for the setting "Plugin update check"
    Then a notification should say "Plugin updates available: hello 1.2.0"

  @setting:pluginAutoUpdate
  Scenario: With auto-update on, a due check installs the update and reloads the plugin live
    Given the plugin "acme/porta-hello" 1.0.0 is installed from Packagist in this project:
      """
      <?php
      return new class implements Porta\Plugin\Api\PortaPlugin {
          public function name(): string { return 'hello'; }
          public function activate(Porta\Plugin\Api\PluginApi $api): void {}
      };
      """
    And the store was last refreshed long ago
    And Packagist publishes "acme/porta-hello" at "1.2.0, 1.0.0"
    # composer update, as it would land 1.2.0: the new code, and its record of it
    And a stand-in "composer" on the PATH doing:
      """
      file_put_contents(__DIR__.'/composer.log', implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
      if ('update' === $argv[1]) {
          file_put_contents('vendor/acme/porta-hello/porta.php', <<<'NEW'
              <?php
              return new class implements Porta\Plugin\Api\PortaPlugin {
                  public function name(): string { return 'hello'; }
                  public function activate(Porta\Plugin\Api\PluginApi $api): void
                  {
                      $api->registerCommand('hi', 'Hello 1.2.0: Say Hi', static fn () => $api->flash('hi from 1.2.0'));
                  }
              };
              NEW);
          file_put_contents('vendor/composer/installed.json', json_encode(['packages' => [['name' => 'acme/porta-hello', 'version' => '1.2.0']]]));
      }
      """
    And the project configuration:
      """
      {"settings": {"pluginAutoUpdate": true}}
      """
    When I start the app with the arguments "a.php"
    Then a notification should say "Updated hello"
    And the file ".stand-ins/composer.log" should contain "update acme/porta-hello:1.2.0 --with-dependencies"
    When I find the action "Hello 1.2.0"
    Then I should see "Hello 1.2.0: Say Hi"

  Scenario: Update all (Shift+U) on the Updates tab installs every update, live
    Given the plugin "acme/porta-hello" 1.0.0 is installed from Packagist in this project:
      """
      <?php
      return new class implements Porta\Plugin\Api\PortaPlugin {
          public function name(): string { return 'hello'; }
          public function activate(Porta\Plugin\Api\PluginApi $api): void {}
      };
      """
    And the store was last refreshed long ago
    And Packagist publishes "acme/porta-hello" at "1.2.0, 1.0.0"
    # composer require, as Update all asks it to land 1.2.0: the new code, and its record of it
    And a stand-in "composer" on the PATH doing:
      """
      file_put_contents(__DIR__.'/composer.log', implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
      if ('require' === $argv[1]) {
          file_put_contents('vendor/acme/porta-hello/porta.php', <<<'NEW'
              <?php
              return new class implements Porta\Plugin\Api\PortaPlugin {
                  public function name(): string { return 'hello'; }
                  public function activate(Porta\Plugin\Api\PluginApi $api): void
                  {
                      $api->registerCommand('hi', 'Hello 1.2.0: Say Hi', static fn () => $api->flash('hi from 1.2.0'));
                  }
              };
              NEW);
          file_put_contents('vendor/composer/installed.json', json_encode(['packages' => [['name' => 'acme/porta-hello', 'version' => '1.2.0']]]));
      }
      """
    When I start the app with the arguments "a.php"
    And I use the action "plugins_store"
    And I focus "#settings-store"
    And I press "u"
    Then I should see "Update all"
    When I press "Shift+U"
    Then a notification should say "Updated hello"
    And the file ".stand-ins/composer.log" should contain "require acme/porta-hello:1.2.0 --update-with-dependencies"
    When I find the action "Hello 1.2.0"
    Then I should see "Hello 1.2.0: Say Hi"
