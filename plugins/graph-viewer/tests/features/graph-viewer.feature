Feature: The graph viewer
  A Graphviz, Mermaid or PlantUML document opens as its source with the
  diagram beside it. The picture zooms and pans like an image; a graph's
  nodes can be selected one after another or found by name, and its labels
  drawn as terminal text instead of a bitmap. The tools are the stand-ins
  the PHPUnit suites use, named by the plugin's settings, and nothing else
  is on the PATH.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And only PHP is on the PATH
    And a file "g.dot" containing:
      """
      digraph G { rankdir=LR; A -> B [label="calls"]; B -> C; }
      """

  @setting:graph-viewer.dotPath
  Scenario: The dot the settings name lays the graph out
    Given the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"dotPath": "%fakes%/fake-dot"}}}
      """
    And I start the app with the arguments "g.dot"
    Then I should see "[Split]"
    And the editor should contain "A -> B"
    When I use the action "preview_only"
    Then I should see "3 nodes · 2 edges"
    And the element "#graph-viewer" should be focused

  Scenario: Without dot, the picture is drawn natively, with no node model
    Given I start the app with the arguments "g.dot"
    When I use the action "preview_only"
    Then I should see "Fit ("
    And I should not see "3 nodes · 2 edges"

  Scenario: Selecting the nodes one after another, and finding one by name
    Given the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"dotPath": "%fakes%/fake-dot"}}}
      """
    And I start the app with the arguments "g.dot"
    When I use the action "preview_only"
    Then I should see "3 nodes · 2 edges"
    When I use the action "graph_select_next"
    Then I should see "3 nodes · 2 edges · A"
    When I use the action "graph_select_next"
    Then I should see "3 nodes · 2 edges · B"
    When I use the action "graph_select_prev"
    Then I should see "3 nodes · 2 edges · A"
    When I use the action "graph_find_node"
    Then I should see "0 in · 1 out"
    And I should see "1 in · 0 out"
    When I type "C"
    And I press "Enter"
    Then I should see "3 nodes · 2 edges · C"

  @command:graph-viewer:find-node
  Scenario: Finding a node from the command palette
    Given the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"dotPath": "%fakes%/fake-dot"}}}
      """
    And I start the app with the arguments "g.dot"
    When I use the action "preview_only"
    Then I should see "3 nodes · 2 edges"
    When I find the action "Graph: Find Node"
    And I press "Enter"
    Then I should see "1 in · 1 out"
    When I type "B"
    And I press "Enter"
    And I focus "#graph-viewer"
    Then I should see "3 nodes · 2 edges · B"

  Scenario: Zooming in, out, to the actual size and back to fit
    Given the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"dotPath": "%fakes%/fake-dot"}}}
      """
    And I start the app with the arguments "g.dot"
    When I use the action "preview_only"
    Then I should see "3 nodes · 2 edges  Fit ("
    When I use the action "graph_zoom_actual"
    Then I should see "3 nodes · 2 edges  100%"
    When I use the action "graph_zoom_in"
    Then I should see "3 nodes · 2 edges  200%"
    When I use the action "graph_zoom_out"
    Then I should see "3 nodes · 2 edges  100%"
    When I use the action "graph_zoom_fit"
    Then I should see "3 nodes · 2 edges  Fit ("

  Scenario: The arrows pan a graph larger than the pane
    Given the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"dotPath": "%fakes%/fake-dot"}}}
      """
    And I start the app with the arguments "g.dot"
    When I use the action "preview_only"
    Then I should see "3 nodes · 2 edges"
    When I use the action "graph_zoom_actual"
    And I use the action "graph_zoom_in"
    And I use the action "graph_zoom_in"
    And I note which part of the picture is in view
    And I use the action "graph_pan_right"
    Then the view should have moved right
    When I note which part of the picture is in view
    And I use the action "graph_pan_down"
    Then the view should have moved down
    When I note which part of the picture is in view
    And I use the action "graph_pan_left"
    Then the view should have moved left
    When I note which part of the picture is in view
    And I use the action "graph_pan_up"
    Then the view should have moved up

  Scenario: The cell rendering draws the labels as text
    Given the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"dotPath": "%fakes%/fake-dot"}}}
      """
    And I start the app with the arguments "g.dot"
    When I use the action "preview_only"
    Then I should see "3 nodes · 2 edges"
    And I should not see "calls"
    When I use the action "graph_render_mode"
    Then I should see "calls"
    When I use the action "graph_render_mode"
    Then I should not see "calls"

  @setting:graph-viewer.renderMode
  Scenario: A graph opens in the rendering the setting names
    Given the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"dotPath": "%fakes%/fake-dot", "renderMode": "cells"}}}
      """
    And I start the app with the arguments "g.dot"
    When I use the action "preview_only"
    Then I should see "3 nodes · 2 edges"
    And I should see "calls"

  @command:graph-viewer:export-svg @command:graph-viewer:export-png @command:graph-viewer:export-pdf
  Scenario: Exporting the graph beside its file
    Given the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"dotPath": "%fakes%/fake-dot"}}}
      """
    And I start the app with the arguments "g.dot"
    When I use the action "preview_only"
    Then I should see "3 nodes · 2 edges"
    When I find the action "Graph: Export SVG"
    And I press "Enter"
    Then the file "g.svg" should contain "-Tsvg"
    When I find the action "Graph: Export PNG"
    And I press "Enter"
    Then the file "g.png" should contain "-Tpng"
    When I find the action "Graph: Export PDF"
    And I press "Enter"
    Then the file "g.pdf" should contain "-Tpdf"
