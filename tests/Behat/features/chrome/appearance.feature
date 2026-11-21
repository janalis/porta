Feature: Appearance
  The Interface settings change how Porta looks, live: the colour theme, the
  language of the UI (Hebrew mirrors the layout right to left), the shape of
  the cursor, where the tab strip sits, the breadcrumb trail, and what a 100%
  zoom means in the viewers.

  Background:
    Given a PHP project
    And a file "src/a.php" containing:
      """
      <?php
      return 1;
      """

  @setting:theme
  Scenario Outline: The theme colours the code
    Given the project configuration:
      """
      {"settings": {"theme": "<theme>"}}
      """
    When I start the app with the arguments "src/a.php"
    Then the text "return" should be colored "<keyword>"

    Examples:
      | theme         | keyword |
      | dark          | 176     |
      | light         | 91      |
      | high-contrast | 213     |

  @setting:theme
  Scenario: The Settings view switches the theme live, and keeps it
    When I start the app with the arguments "src/a.php"
    Then the text "return" should be colored "176"
    When I press "Ctrl+,"
    And I press "Ctrl+F"
    And I type "theme"
    And I press "Enter"
    And I press "]"
    Then I should see "Theme light"
    And the file ".porta.json" should contain "light"
    When I press "Escape"
    Then the text "return" should be colored "91"

  @setting:language
  Scenario: The UI speaks the language chosen
    Given the project configuration:
      """
      {"settings": {"language": "fr"}}
      """
    When I start the app with the arguments "src/a.php"
    Then I should see "Naviguer"
    And I should see "Aide"
    And I should not see "Navigate"

  @setting:language
  Scenario: Hebrew mirrors the layout, the tree on the right
    Given the project configuration:
      """
      {"settings": {"language": "he"}}
      """
    When I start the app with the arguments "src/a.php"
    # The screen is read cell by cell, left to right: "Navigate" (ניווט) as drawn
    Then I should see "טווינ"
    And I should see "composer.json" after "return 1;"

  Scenario: Without a language set, the tree stays on the left
    When I start the app with the arguments "src/a.php"
    Then I should see "composer.json" before "return 1;"

  @setting:cursorShape
  Scenario Outline: The cursor takes the shape chosen
    Given the project configuration:
      """
      {"settings": {"cursorShape": "<shape>"}}
      """
    When I start the app with the arguments "src/a.php"
    Then the cursor should be a blinking <shape>

    Examples:
      | shape     |
      | bar       |
      | block     |
      | underline |

  @setting:tabBarPosition
  Scenario: The tab strip can sit below the editor
    Given the project configuration:
      """
      {"settings": {"tabBarPosition": "bottom"}}
      """
    When I start the app with the arguments "src/a.php"
    Then I should see "a.php ✖" below "return 1;"

  Scenario: By default the tab strip sits above the editor
    When I start the app with the arguments "src/a.php"
    Then I should see "a.php ✖" above "return 1;"

  @setting:breadcrumbs
  Scenario: The breadcrumb trail can be turned off
    Given the project configuration:
      """
      {"settings": {"breadcrumbs": false}}
      """
    When I start the app with the arguments "src/a.php"
    Then I should see "return 1;"
    And I should not see "src › a.php"

  Scenario: The breadcrumb trail is on by default
    When I start the app with the arguments "src/a.php"
    Then I should see "src › a.php"

  @setting:pixelRatio
  Scenario: The pixel ratio says what a 100% zoom means in the viewers
    Given the project configuration:
      """
      {"settings": {"pixelRatio": "2"}}
      """
    When I start the app with the arguments "src/a.php"
    Then the viewers should draw 2 device pixels per logical pixel
