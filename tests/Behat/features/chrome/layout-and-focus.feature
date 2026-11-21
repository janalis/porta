Feature: Layout and focus
  The project tree sits beside the editor and the dock below them. The keys
  move the focus between the panes and size them, the layout is kept in
  .porta/layout.json, and a narrow terminal folds the tree away.

  Background:
    Given a PHP project
    And a file "a.php" containing:
      """
      <?php
      $a = 1;
      """

  @action:ide_toggle_focus
  Scenario: Ctrl+T switches between the tree and the editor
    When I start the app with the arguments "a.php"
    Then the element "#editor" should be focused
    When I use the action "ide_toggle_focus"
    Then the element "#tree" should be focused
    When I use the action "ide_toggle_focus"
    Then the element "#editor" should be focused

  @action:ide_project_tree
  Scenario: Alt+1 focuses the project tree
    When I start the app with the arguments "a.php"
    And I use the action "ide_project_tree"
    Then the element "#tree" should be focused
    When I use the action "ide_project_tree"
    Then the element "#tree" should be focused

  @action:focus_next @action:focus_previous
  Scenario: F6 and Ctrl+F6 cycle the focus across the panes
    When I start the app with the arguments "a.php"
    And I press "F6"
    Then the element "#tree" should be focused
    When I press "F6"
    Then the element "#editor" should be focused
    When I press "Ctrl+F6"
    Then the element "#tree" should be focused
    When I press "Ctrl+F6"
    Then the element "#editor" should be focused

  @action:resize_pane_grow @action:resize_pane_shrink
  Scenario: Shift+F12 and Shift+F11 grow and shrink the focused pane, and the layout is kept
    When I start the app with the arguments "a.php"
    Then the tree should be 30 columns wide
    When I press "Shift+F12"
    Then the tree should be 30 columns wide
    When I use the action "ide_project_tree"
    And I press "Shift+F12"
    Then the tree should be 32 columns wide
    When I press "Shift+F11"
    And I press "Shift+F11"
    Then the tree should be 28 columns wide
    And the file ".porta/layout.json" should contain "sidebarWidthPercent"
    When I restart the app
    Then the tree should be 28 columns wide

  @action:ide_maximise_panel
  Scenario: Ctrl+Shift+' maximises the bottom panel and puts it back
    When I start the app with the arguments "a.php"
    And I press "Alt+6"
    Then I should eventually see "Problems"
    When I note the height of the bottom panel
    And I press "Ctrl+Shift+'"
    Then the bottom panel should be taller than noted
    When I press "Ctrl+Shift+'"
    Then the bottom panel should be as tall as noted

  Scenario: A narrow terminal folds the tree away, and a wide one brings it back
    When I start the app with the arguments "a.php"
    Then I should see an element matching "#tree"
    When I resize the terminal to 50x30
    Then I should not see an element matching "#tree"
    And I should see "$a = 1;"
    When I resize the terminal to 100x30
    Then I should see an element matching "#tree"
