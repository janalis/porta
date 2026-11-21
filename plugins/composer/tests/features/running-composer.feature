Feature: Composer from the editor
  Show Outdated Packages asks Composer which direct dependencies have newer
  releases: the Packages tab lists them and composer.json marks the lines
  requiring them. Install, Update All, Update Package at Caret, Require
  Package… and Remove Package at Caret run Composer in a terminal tab. The
  binary is the one the composer binary setting names, else the PATH.

  Background:
    Given a PHP project
    And the bundled plugin "composer" is enabled
    And only the stand-ins are on the PATH
    And a file "composer.json" containing:
      """
      {
          "name": "app/demo",
          "require": {
              "php": ">=8.5",
              "a/one": "^1.0"
          },
          "require-dev": {
              "c/three": "^1.0"
          }
      }
      """
    And a file "outdated.json" containing:
      """
      {"installed": [
          {"name": "a/one", "version": "1.0.0", "latest": "1.1.0", "latest-status": "semver-safe-update", "abandoned": false},
          {"name": "c/three", "version": "1.2.0", "latest": "2.0.0", "latest-status": "update-possible", "abandoned": false},
          {"name": "z/fine", "version": "1.0.0", "latest": "1.0.0", "latest-status": "up-to-date", "abandoned": false}
      ]}
      """
    # The plugin's own stand-in: notes each call in composer.log, answers outdated from outdated.json
    And an executable "tools/composer" doing:
      """
      require '%repo%/plugins/composer/tests/fixtures/composer.php';
      """
    And the plugin setting "composer.binary" is "%workspace%/tools/composer"

  Scenario: The outdated packages are listed, and marked in composer.json
    Given I start the app with the arguments "composer.json"
    When I use the action "composer:outdated"
    Then I should see "a/one 1.0.0 ↑ 1.1.0"
    And I should see "c/three 1.2.0 ⇑ 2.0.0 (past the constraint)"
    And I should not see "z/fine"
    # The mark after the line requiring it: "a/one": "^1.0"  ↑ 1.1.0
    And I should see text matching "/a\/one\W+\^1\.0\W+↑ 1\.1\.0/"
    And the file "composer.log" should contain "outdated --direct --format=json --no-plugins --no-interaction"

  Scenario: Install runs composer install in a terminal tab
    Given I start the app with the arguments "composer.json"
    When I use the action "composer:install"
    Then I should see "composer install finished"
    And the file "composer.log" should contain exactly:
      """
      install
      """

  Scenario: Update All runs composer update, and a failure says its exit code
    Given a file "exit-code" containing "3"
    And I start the app with the arguments "composer.json"
    When I use the action "composer:update"
    Then I should see "exited with code 3"
    And the file "composer.log" should contain exactly:
      """
      update
      """

  Scenario: Update Package at Caret updates the package required on the caret's line
    Given I start the app with the arguments "composer.json"
    When I put the caret on line 5
    And I use the action "composer:update_at_caret"
    Then I should see "composer update finished"
    And the file "composer.log" should contain "update a/one"

  Scenario: Require Package… asks what to require, and requires it
    Given I start the app with the arguments "composer.json"
    When I use the action "composer:require"
    Then I should see "Require (vendor/name"
    When I type "b/two --dev"
    And I press "Enter"
    Then I should see "composer require finished"
    And the file "composer.log" should contain "require b/two --dev"
    And the file "composer.json" should contain "b/two"

  Scenario: Remove Package at Caret removes the package required on the caret's line
    Given I start the app with the arguments "composer.json"
    When I put the caret on line 8
    And I use the action "composer:remove_at_caret"
    Then I should see "composer remove finished"
    And the file "composer.log" should contain "remove c/three"

  Scenario: Off a package's line, the caret commands say where to put it
    Given I start the app with the arguments "composer.json"
    When I put the caret on line 2
    And I use the action "composer:remove_at_caret"
    Then I should see "Put the caret on a package"
    And the file "composer.log" should not exist

  @setting:composer.binary
  Scenario: Without the composer binary setting, the composer on the PATH runs
    Given the project configuration:
      """
      {"plugins": {"composer": {"binary": ""}}}
      """
    And a stand-in "composer" on the PATH doing:
      """
      file_put_contents('composer.log', 'composer from the PATH: '.implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
      """
    And I start the app with the arguments "composer.json"
    When I use the action "composer:install"
    Then I should see "composer install finished"
    And the file "composer.log" should contain "composer from the PATH: install"
