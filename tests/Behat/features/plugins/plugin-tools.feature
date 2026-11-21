Feature: The tools plugins run
  A plugin declares the programs it drives, and where each installs from.
  Plugins: Install Missing Tools installs what the enabled plugins require,
  and Plugins: Update Tools updates every copy Porta manages, through
  whatever put it there. In the background, Porta looks for newer versions of
  those tools at the toolUpdateCheck cadence and says once what it found.

  Here the tool is "widget", from a brew that is a stand-in: install lands
  widget 1.0.0 in its Cellar, upgrade lands 2.0.0, and info says 2.0.0 is out.

  Background:
    Given a PHP project
    And a Porta home of my own
    And a file "a.php" containing "<?php"
    And a file ".porta/plugins/needy/composer.json" containing:
      """
      {"name": "test/needy", "type": "porta-plugin", "extra": {"porta": {"tools": [{"name": "widget", "sources": [["brew", "widget"]]}]}}}
      """
    And a file ".porta/plugins/needy/porta.php" containing:
      """
      <?php
      use Porta\Plugin\Api\PluginApi;
      use Porta\Plugin\Api\PortaPlugin;

      return new class implements PortaPlugin {
          public function name(): string { return 'needy'; }
          public function activate(PluginApi $api): void {}
      };
      """
    And a stand-in "brew" on the PATH doing:
      """
      file_put_contents(__DIR__.'/brew.log', implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
      $land = static function (string $version): void {
          $dir = dirname(__DIR__).'/Cellar/widget/'.$version.'/bin';
          @mkdir($dir, 0o777, true);
          file_put_contents($dir.'/widget', '#!'.PHP_BINARY." -n\n<?php echo 'widget ".$version."', PHP_EOL;\n");
          chmod($dir.'/widget', 0o755);
          @unlink(__DIR__.'/widget');
          symlink($dir.'/widget', __DIR__.'/widget');
      };
      match ($argv[1]) {
          'install' => $land('1.0.0'),
          'upgrade' => $land('2.0.0'),
          'info' => print(json_encode(['formulae' => [['versions' => ['stable' => '2.0.0']]]])),
      };
      """

  Scenario: Install Missing Tools installs what an enabled plugin requires
    Given I start the app with the arguments "a.php"
    When I use the action "plugins_install_tools"
    Then I should see "Installed widget 1.0.0"
    And the file ".stand-ins/brew.log" should contain "install widget"

  Scenario: With every tool there, Install Missing Tools has nothing to do
    Given I start the app with the arguments "a.php"
    And I use the action "plugins_install_tools"
    And I should see "Installed widget 1.0.0"
    When I use the action "plugins_install_tools"
    Then I should see "Nothing to install"

  Scenario: Update Tools updates a managed copy through the manager that put it there
    Given I start the app with the arguments "a.php"
    And I use the action "plugins_install_tools"
    And I should see "Installed widget 1.0.0"
    When I use the action "plugins_update_tools"
    Then I should see "Updated widget to 2.0.0"
    And the file ".stand-ins/brew.log" should contain "upgrade widget"

  @setting:toolUpdateCheck
  Scenario: A due update check says once which tools have a newer version
    Given brew has installed widget 1.0.0
    And the tools were last checked for updates long ago
    And I start the app with the arguments "a.php"
    Then I should see "Updates available: widget 2.0.0"
    And the file ".stand-ins/brew.log" should contain "info --json=v2 --formula widget"

  @setting:toolUpdateCheck
  Scenario: With the update check off nothing is asked, until it is switched on
    Given brew has installed widget 1.0.0
    And the tools were last checked for updates long ago
    And the project configuration:
      """
      {"settings": {"toolUpdateCheck": "off"}}
      """
    And I start the app with the arguments "a.php"
    When I open the Settings page "General"
    Then the file ".stand-ins/brew.log" should not exist
    When I choose "weekly" for the setting "Check tools for updates"
    Then I should see "Updates available: widget 2.0.0"
    And the project configuration should have "settings.toolUpdateCheck" set to "weekly"
