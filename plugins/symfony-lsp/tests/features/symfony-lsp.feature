Feature: Symfony language server
  symfony-lsp serves Twig, YAML, XML, JSON and dotenv, and PHP beside a general
  PHP server. What it is told at start-up comes from the plugin's settings.
  A stub stands in for it: the logging language server, which writes every
  message it receives to lsp.log.

  Background:
    Given a PHP project
    And the environment variable "PATH" is "/usr/bin:/bin"
    And a stub tool "bin/symfony-lsp" containing:
      """
      #!/bin/sh
      exec "%php%" "%support%/logging-lsp-server.php" "%workspace%/lsp.log"
      """
    And a file "templates/base.twig" containing "{{ app.user }}"
    And the bundled plugin "symfony-lsp" is enabled

  @setting:symfony-lsp.binary
  Scenario: The binary setting names the server that serves Twig
    Given the "symfony-lsp" plugin setting "binary" is the workspace path "bin/symfony-lsp"
    When I start the app with the arguments "templates/base.twig"
    Then I should see "LSP:symfony-lsp"
    And the language server logged in "lsp.log" should have been initialized with:
      """
      {"phpCommand": ["php"], "environment": "dev", "runtimeIndexing": false, "workspaceTrust": false}
      """

  @setting:symfony-lsp.runtime-indexing @setting:symfony-lsp.php-command @setting:symfony-lsp.environment
  Scenario: Runtime indexing, the PHP command and the environment reach the server
    Given the "symfony-lsp" plugin setting "binary" is the workspace path "bin/symfony-lsp"
    And the project configuration:
      """
      {"plugins": {"symfony-lsp": {"runtime-indexing": true, "php-command": "symfony php", "environment": "prod"}}}
      """
    When I start the app with the arguments "templates/base.twig"
    Then the language server logged in "lsp.log" should have been initialized with:
      """
      {"phpCommand": ["symfony", "php"], "environment": "prod", "runtimeIndexing": true, "workspaceTrust": true}
      """

  @setting:symfony-lsp.languages
  Scenario: A language left out of the claim is not served
    Given the "symfony-lsp" plugin setting "binary" is the workspace path "bin/symfony-lsp"
    And a file "config/services.yaml" containing "services: ~"
    And the project configuration:
      """
      {"plugins": {"symfony-lsp": {"languages": "yaml"}}}
      """
    When I start the app with the arguments "templates/base.twig"
    Then I should see "LSP:no server"
    When I use the action "goto_file"
    And I type "services.yaml"
    And I should see "services.yaml" in the dialog "Go to File"
    And I press "Enter"
    Then I should see "LSP:symfony-lsp"
