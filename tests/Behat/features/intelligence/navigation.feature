Feature: Code navigation
  From a symbol, the language server knows where it is declared, what
  implements it, what type it has, who uses it and who calls it. Porta jumps
  there, shows it in place, or lists it in the results panel.

  Background:
    Given a PHP project
    And the mock language server is configured
    And a file "src/Target.php" containing:
      """
      <?php

      // header
      class Target
      {
      }
      """
    And a file "src/Demo.php" containing:
      """
      <?php

      class Demo
      {
          public function greet(): string
          {
              return 'x';
          }
      }
      """

  Scenario: Go to declaration opens the file that declares the symbol
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I put the caret at line 5, column 21
    And I use the action "goto_definition"
    Then the editor should show "src/Target.php"
    And the caret should be at line 4, column 7

  Scenario: Several implementations open a chooser
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I put the caret at line 5, column 21
    And I use the action "goto_implementation"
    Then I should see "Choose target"
    And I should see "Target.php"
    And the editor should show "src/Demo.php"

  Scenario: A single type definition is jumped to at once
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I put the caret at line 5, column 21
    And I use the action "goto_type_definition"
    Then the editor should show "src/Target.php"

  Scenario: Peek shows the declaration in place, and Enter goes there
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I put the caret at line 5, column 21
    And I use the action "peek_definition"
    Then I should see "Peek: src/Target.php:4"
    And I should see "class Target"
    And the editor should show "src/Demo.php"
    When I press "Enter"
    Then the editor should show "src/Target.php"

  Scenario: Quick documentation shows what the server says about the symbol
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I put the caret at line 5, column 21
    And I use the action "quick_doc"
    Then I should see "Says hello to the subject."

  Scenario: Find usages lists every place in the results panel
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I put the caret at line 5, column 21
    And I use the action "find_usages"
    Then I should see "Usages"
    And I should see "2 results"
    And I should see "Target.php"

  Scenario: Call hierarchy lists the callers of the function at the caret
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I put the caret at line 5, column 21
    And I use the action "call_hierarchy"
    Then I should see "Callers of greet"
    And I should see "1 result"
    And I should see "src/Demo.php  (1)"

  Scenario: Calls from here lists what the function calls
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I put the caret at line 5, column 21
    And I use the action "outgoing_calls"
    Then I should see "Calls from greet"
    And I should see "1 result"

  Scenario: File structure lists the file's symbols and jumps to one
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I use the action "file_structure"
    Then I should see "File Structure"
    And I should see "greet"
    When I press "Down"
    And I press "Enter"
    Then the caret should be on line 5

  Scenario: Go to symbol finds a symbol anywhere in the project
    Given I start the app with the arguments "src/Demo.php"
    And the language server is ready
    When I use the action "ide_goto_symbol"
    And I type "greet"
    Then I should see "Greeter"
    When I press "Enter"
    Then the editor should show "src/Target.php"
    And the caret should be on line 4

  Scenario: Go to file finds a file by part of its name
    Given I start the app with the arguments "src/Demo.php"
    When I use the action "goto_file"
    And I type "Targ"
    Then I should see "Target.php" in the dialog "Go to File"
    When I press "Enter"
    Then the editor should show "src/Target.php"

  Scenario: Go to test goes from a class to its test and back
    Given a file "src/Foo.php" containing "<?php"
    And a file "tests/Unit/FooTest.php" containing "<?php"
    And I start the app with the arguments "src/Foo.php"
    When I use the action "ide_goto_test"
    Then the editor should show "tests/Unit/FooTest.php"
    When I use the action "ide_goto_test"
    Then the editor should show "src/Foo.php"

  Scenario: Go to super method goes to the declaration a method overrides
    Given a file "src/Base.php" containing:
      """
      <?php

      class Base
      {
          public function greet(): string
          {
              return 'base';
          }
      }
      """
    And a file "src/Child.php" containing:
      """
      <?php

      class Child extends Base
      {
          public function greet(): string
          {
              return 'child';
          }
      }
      """
    And I start the app with the arguments "src/Child.php"
    When I put the caret at line 7, column 9
    And I use the action "ide_goto_super"
    Then the editor should show "src/Base.php"
    And the caret should be on line 5

  Scenario: A method that overrides nothing says so
    Given I start the app with the arguments "src/Demo.php"
    When I put the caret at line 7, column 9
    And I use the action "ide_goto_super"
    Then a toast should say "No super method"
    And the editor should show "src/Demo.php"
