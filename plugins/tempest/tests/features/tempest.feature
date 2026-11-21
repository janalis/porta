Feature: Tempest highlighting
  tempest/highlight is Porta's in-process highlighting engine. It claims every
  language by default; its Languages setting narrows the claim, and a language
  it does not claim is left uncoloured.

  Background:
    Given a PHP project
    And a file "src/A.php" containing:
      """
      <?php
      return 1;
      """

  @setting:tempest.languages
  Scenario: By default PHP is coloured
    When I start the app with the arguments "src/A.php"
    Then the text "return" should have a color

  @setting:tempest.languages
  Scenario: A language left out of the claim is not coloured
    Given the project configuration:
      """
      {"plugins": {"tempest": {"languages": "json"}}}
      """
    When I start the app with the arguments "src/A.php"
    And I should see "return 1;"
    Then the text "return" should have no color
