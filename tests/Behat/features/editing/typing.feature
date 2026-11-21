Feature: Typing, new lines and indentation
  Enter splits the line and keeps its indent, opening a block between a pair
  of braces; Shift+Enter and Ctrl+Alt+Enter start a line below or above
  wherever the caret is; Ctrl+Shift+Enter completes the statement; Tab and
  Shift+Tab indent and outdent with the file's own unit. Undo takes back a run
  of typing at once, and redo puts it back.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      function f() {}
          $x = 1;
      """
    And I start the app with the arguments "a.php"

  Scenario: Enter between braces opens an indented line with the closer below
    Given the caret is at line 2, column 15
    When I use the action "new_line"
    And I type "return;"
    Then the editor text should be:
      """
      <?php
      function f() {
          return;
      }
          $x = 1;
      """

  Scenario: Start a new line below or above without splitting this one
    Given the caret is at line 3, column 7
    When I use the action "insert_line_below"
    And I type "$y = 2;"
    And I use the action "insert_line_above"
    And I type "$z = 3;"
    Then the editor text should be:
      """
      <?php
      function f() {}
          $x = 1;
          $z = 3;
          $y = 2;
      """

  Scenario: Complete a statement by closing its brackets and ending it
    Given the caret is at line 3, column 12
    When I type "foo(1, bar(2"
    And I use the action "complete_statement"
    And I type "done();"
    Then the editor text should be:
      """
      <?php
      function f() {}
          $x = foo(1, bar(2));
          done();
      """

  Scenario: Complete an if into a block
    Given the caret is at line 3, column 12
    When I use the action "delete_to_line_end"
    And I use the action "cursor_line_start"
    And I use the action "delete_to_line_end"
    And I type "if ($x > 1"
    And I use the action "complete_statement"
    And I type "go();"
    Then the editor text should be:
      """
      <?php
      function f() {}
      if ($x > 1) {
          go();
      }
      """

  Scenario: Tab indents with the file's unit and Shift+Tab outdents
    Given the caret is at line 2, column 1
    When I use the action "indent"
    Then the editor should contain "    function f() {}"
    When I use the action "prev_tab_stop"
    And I use the action "prev_tab_stop"
    Then the editor text should be:
      """
      <?php
      function f() {}
          $x = 1;
      """
    Given the caret is at line 3, column 5
    When I use the action "prev_tab_stop"
    Then the editor should contain "\n$x = 1;"

  Scenario: Shift+Tab walks back through a live template's tab stops
    Given the caret is at line 4, column 1
    When I type "fore"
    And I use the action "indent"
    Then the selection should be "$items"
    When I use the action "indent"
    Then the selection should be "$item"
    When I use the action "prev_tab_stop"
    Then the selection should be "$items"
    When I type "$rows"
    Then the editor should contain "foreach ($rows as $item) {"

  Scenario: Undo takes back a run of typing at once, and redo puts it back
    Given the caret is at line 3, column 12
    When I type " // note"
    Then the editor should contain "$x = 1; // note"
    When I use the action "undo"
    Then the editor should not contain "note"
    When I use the action "redo"
    Then the editor should contain "$x = 1; // note"
