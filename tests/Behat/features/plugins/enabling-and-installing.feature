Feature: Enabling, installing and removing plugins
  A plugin is switched on and off without a restart, from the palette or its
  Settings page. The store's Installed tab installs one from a local path, a
  git URL or a package name, into the project or for every project, and a
  plugin's own page removes it with two presses.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And a file "a.php" containing "<?php"

  Scenario: Plugins: Enable… switches a bundled plugin on, at once
    Given I start the app with the arguments "a.php"
    When I use the action "plugins_enable"
    Then I should see "Enable plugin"
    When I type "phpstan"
    And I press "Enter"
    Then the project configuration should list "phpstan" in "enabledPlugins"
    When I find the action "PHPStan: Analyse"
    Then I should see "PHPStan: Analyse Current File"

  Scenario: Plugins: Disable… switches a plugin off, at once
    Given the bundled plugin "phpstan" is enabled
    And I start the app with the arguments "a.php"
    When I use the action "plugins_disable"
    Then I should see "Disable plugin"
    When I type "phpstan"
    And I press "Enter"
    Then the project configuration should not list "phpstan" in "enabledPlugins"
    When I find the action "PHPStan: Analyse"
    Then I should not see "PHPStan: Analyse Current File"

  Scenario: A plugin is switched off and on again from its Settings page
    Given the bundled plugin "phpstan" is enabled
    And I start the app with the arguments "a.php"
    When I open the Settings page "phpstan"
    And I select the setting "Enabled"
    And I press "Space"
    Then the project configuration should not list "phpstan" in "enabledPlugins"
    And the setting "Enabled" should be "off"
    When I press "Space"
    Then the project configuration should list "phpstan" in "enabledPlugins"

  Scenario: A plugin installed from a local path is on at once, its key and commands with it
    Given the stand-in composer is on the PATH
    And a file "plugin-src/greeter/composer.json" containing:
      """
      {"name": "test/greeter", "type": "porta-plugin"}
      """
    And a file "plugin-src/greeter/porta.php" containing:
      """
      <?php
      use Porta\Plugin\Api\PluginApi;
      use Porta\Plugin\Api\PortaPlugin;

      return new class implements PortaPlugin {
          public function name(): string { return 'greeter'; }
          public function activate(PluginApi $api): void
          {
              $api->registerCommand('hi', 'Hi from greeter', static function () use ($api): void {
                  file_put_contents($api->projectRoot().'/greeted', 'hi');
                  $api->flash('greeter says hi');
              }, ['ctrl+alt+k']);
          }
      };
      """
    And I start the app with the arguments "a.php"
    When I open the Settings page "Plugins"
    And I install the plugin from "%workspace%/plugin-src/greeter"
    Then I should see "Plugin installed"
    And the file ".porta/plugins/greeter/porta.php" should exist
    When I press "Ctrl+Alt+K"
    Then the file "greeted" should exist

  Scenario: Removing a plugin takes two presses, and it is gone at once
    Given a file ".porta/plugins/demo/porta.php" containing:
      """
      <?php
      use Porta\Plugin\Api\PluginApi;
      use Porta\Plugin\Api\PortaPlugin;

      return new class implements PortaPlugin {
          public function name(): string { return 'demo'; }
          public function activate(PluginApi $api): void
          {
              $api->registerCommand('hi', 'Hi from demo', static fn () => $api->flash('demo says hi'));
          }
      };
      """
    And I start the app with the arguments "a.php"
    When I open the Settings page "demo"
    And I select the setting "Installed in this project"
    And I press "Enter"
    Then I should see "Press Enter again to remove"
    And the file ".porta/plugins/demo/porta.php" should exist
    When I press "Enter"
    Then the file ".porta/plugins/demo/porta.php" should not exist
    When I press "Escape"
    And I find the action "Hi from demo"
    Then I should see "no results" in the dialog "Find Action"
