Feature: The project's architecture
  Plugins → Project Architecture writes the project's namespace graph to
  architecture.dot and shows it, the picture alone; Ctrl+Alt+V brings its
  DOT back. A command of your own can supply the graph instead, and the file
  can go to another directory.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And only PHP is on the PATH
    And a file "composer.json" containing:
      """
      {"autoload": {"psr-4": {"App\\": "src/"}}}
      """
    And a file "src/Shop/Cart.php" containing:
      """
      <?php
      namespace App\Shop;
      use App\Billing\Invoice;
      final class Cart {}
      """
    And a file "src/Billing/Invoice.php" containing:
      """
      <?php
      namespace App\Billing;
      final class Invoice {}
      """

  @command:graph-viewer:architecture
  Scenario: The namespace graph opens, the picture alone, with its DOT a toggle away
    Given I start the app
    When I find the action "Project Architecture"
    And I press "Enter"
    Then the file "architecture.dot" should contain "Shop"
    And I should see "Project Architecture"
    And I should see "[Preview]"
    When I use the action "toggle_preview"
    Then I should see "[Editor]"
    And the editor should contain "Shop"

  @command:graph-viewer:architecture @setting:graph-viewer.command
  Scenario: A command of your own supplies the graph
    Given the project configuration:
      """
      {"plugins": {"graph-viewer": {"command": "echo 'digraph G { Custom -> Made }'"}}}
      """
    And I start the app
    When I find the action "Project Architecture"
    And I press "Enter"
    Then the file "architecture.dot" should contain "Custom -> Made"

  @command:graph-viewer:architecture @setting:graph-viewer.outputDir
  Scenario: The graph goes to the output directory the settings name
    Given the project configuration:
      """
      {"plugins": {"graph-viewer": {"outputDir": "docs/graphs"}}}
      """
    And I start the app
    When I find the action "Project Architecture"
    And I press "Enter"
    Then the file "docs/graphs/architecture.dot" should contain "digraph"
    And the file "architecture.dot" should not exist
