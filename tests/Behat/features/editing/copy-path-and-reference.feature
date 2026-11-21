Feature: Copy path and reference
  Ctrl+Shift+C copies the shown file's path relative to the project;
  Ctrl+Alt+Shift+C copies a reference to where the caret is: the enclosing
  symbol when there is one, and path:line when there is not. Both reach the
  system clipboard.

  Background:
    Given a PHP project
    And a file "src/Svc.php" containing:
      """
      <?php

      namespace App\Http;

      class Svc
      {
          public function run(): void
          {
              $x = 1;
          }
      }
      """
    And I start the app with the arguments "src/Svc.php"

  Scenario: Copy the file's path
    When I use the action "copy_path"
    Then the clipboard should contain "src/Svc.php"

  Scenario: Copy a reference to the enclosing method
    Given the caret is at line 9, column 9
    When I use the action "copy_reference"
    Then the clipboard should contain "App\Http\Svc::run"

  Scenario: Outside any symbol the reference is the path and the line
    Given the caret is at line 2, column 1
    When I use the action "copy_reference"
    Then the clipboard should contain "src/Svc.php:2"
