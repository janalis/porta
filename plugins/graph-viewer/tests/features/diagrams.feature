Feature: Mermaid and PlantUML diagrams
  Mermaid and PlantUML documents open beside their picture while their
  previews are on, drawn by the tools the settings name. The stand-in tools
  fail on a document that says "broken", each with its own words, which is
  how a scenario tells the configured tool ran.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And only PHP is on the PATH

  @setting:graph-viewer.mermaidPath
  Scenario: The Mermaid CLI the settings name draws the picture
    Given the environment variable "PORTA_FAKE_REPORT_DIR" is "%workspace%"
    And the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"mermaidPath": "%fakes%/fake-mmdc"}}}
      """
    And a file "flow.mmd" containing:
      """
      flowchart LR
        A --> B
      """
    And I start the app with the arguments "flow.mmd"
    Then I should see "[Split]"
    And the file "mermaid-request.json" should contain "flowchart LR"

  @setting:graph-viewer.mermaid
  Scenario: With the Mermaid preview off, a Mermaid file is plain text
    Given the project configuration:
      """
      {"plugins": {"graph-viewer": {"mermaid": false}}}
      """
    And a file "flow.mmd" containing:
      """
      flowchart LR
        A --> B
      """
    And I start the app with the arguments "flow.mmd"
    Then the editor should contain "flowchart LR"
    And I should not see "[Split]"

  @setting:graph-viewer.plantumlPath
  Scenario: The PlantUML the settings name draws the diagram
    Given a recording stand-in "tools/plantuml" for "fake-plantuml"
    And the project configuration naming the test fakes:
      """
      {"plugins": {"graph-viewer": {"plantumlPath": "%workspace%/tools/plantuml"}}}
      """
    And a file "seq.puml" containing:
      """
      @startuml
      Alice -> Bob: hello
      @enduml
      """
    And I start the app with the arguments "seq.puml"
    Then I should see "[Split]"
    And the file "tools/plantuml.calls" should exist

  @setting:graph-viewer.plantuml
  Scenario: With the PlantUML preview off, a PlantUML file is plain text
    Given the project configuration:
      """
      {"plugins": {"graph-viewer": {"plantuml": false}}}
      """
    And a file "seq.puml" containing:
      """
      @startuml
      Alice -> Bob: hello
      @enduml
      """
    And I start the app with the arguments "seq.puml"
    Then the editor should contain "Alice -> Bob"
    And I should not see "[Split]"
