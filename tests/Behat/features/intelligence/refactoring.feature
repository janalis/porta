Feature: Refactoring and quick fixes
  Porta implements no refactoring itself: it asks the language server for the
  ones it has, applies what comes back, and can take the last one back.

  Background:
    Given a PHP project
    And the mock language server is configured
    And a file "src/Target.php" containing:
      """
      <?php

      // header
      class Target
      {
          public int $x = 1;
      }
      """
    And I start the app with the arguments "src/Target.php"
    And the language server is ready

  Scenario: Rename asks for the new name and applies the server's edit
    When I put the caret at line 4, column 8
    And I use the action "rename_symbol"
    Then I should see "Rename to"
    When I press "Backspace" 10 times
    And I type "Greeter"
    And I press "Enter"
    Then line 4 of the editor should be "class Greeter"
    And a toast should say "Renamed in 1 file"

  Scenario: Undo last refactoring takes the whole rename back
    When I put the caret at line 4, column 8
    And I use the action "rename_symbol"
    And I should see "Rename to"
    And I press "Backspace" 10 times
    And I type "Greeter"
    And I press "Enter"
    And line 4 of the editor should be "class Greeter"
    And I use the action "ide_undo_refactoring"
    Then line 4 of the editor should be "class Target"
    And a toast should say "Refactoring undone"

  Scenario: Undo last refactoring with nothing to undo says so
    When I use the action "ide_undo_refactoring"
    Then a toast should say "No refactoring to undo"

  Scenario: Alt+Enter lists the fixes the server has for the caret
    When I put the caret at line 1, column 1
    And I use the action "code_action"
    Then I should see "Quick Fixes"
    And I should see "Import class"
    And I should see "Generate method"
    When I press "Enter"
    Then line 2 of the editor should be "use Acme\Imported;"
    And a toast should say "to 1 file"

  Scenario: Apply quick fix applies the first fix without a list
    Given I should see "✗ 1"
    When I put the caret at line 1, column 1
    And I use the action "apply_quick_fix"
    Then line 2 of the editor should be "use Acme\Imported;"
    And I should not see "Generate method"

  Scenario: Refactor This offers only the refactorings, resolved when picked
    When I select from line 6, column 21 to line 6, column 22
    And I use the action "refactor_this"
    Then I should see "Refactor This"
    And I should see "Extract variable"
    And I should not see "Import class"
    When I press "Down"
    And I press "Enter"
    Then line 2 of the editor should be "$extracted = 1;"

  Scenario: Inline applies the server's one inline at once
    When I put the caret at line 6, column 17
    And I use the action "inline_refactoring"
    Then line 2 of the editor should be "// inlined"
    And I should not see "Inline variable"

  Scenario: Optimize imports applies the server's single answer at once
    When I put the caret at line 3, column 1
    And I use the action "organize_imports"
    Then line 2 of the editor should be "use Acme\Organized;"
    And I should not see "Organize Imports"

  Scenario: Reformat file replaces the file with what the formatter returns
    When I use the action "reformat"
    Then line 3 of the editor should be "formatted();"
    And a toast should say "Reformatted src/Target.php"

  Scenario: Reformat with a selection formats only the selection
    When I select from line 4, column 1 to line 4, column 13
    And I use the action "reformat"
    Then line 4 of the editor should be "FORMATTED"
    And line 6 of the editor should be "    public int $x = 1;"
