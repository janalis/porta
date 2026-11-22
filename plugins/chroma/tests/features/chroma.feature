Feature: Chroma highlighting
  chroma colours the languages Porta's own engine does not know, in a
  subprocess: Porta paints, chroma answers, the buffer repaints. Tempest, which
  claims every language, is off here so chroma is the one asked. A stub stands
  in for it: it calls the first two characters a keyword, and logs each run
  to chroma.log as the lexer and the size it was given.

  Background:
    Given a PHP project
    And a stub tool "bin/chroma" containing:
      """
      #!%php%
      <?php
      $source = (string) stream_get_contents(STDIN);
      file_put_contents(getcwd().'/chroma.log', $argv[array_search('--lexer', $argv, true) + 1].' '.strlen($source)."\n", FILE_APPEND);
      echo json_encode([['type' => 'Keyword', 'value' => substr($source, 0, 2)], ['type' => 'Text', 'value' => substr($source, 2)]]);
      """
    And a file "main.rs" containing "fn main() {}"
    And a file "main.go" containing "package main"
    And the bundled plugin "chroma" is enabled
    And the project configuration:
      """
      {"disabledPlugins": ["tempest"]}
      """
    And the "chroma" plugin setting "binary" is the workspace path "bin/chroma"

  @setting:chroma.binary
  Scenario: The binary setting names the lexer that colours the buffer
    Given the project configuration:
      """
      {"plugins": {"chroma": {"debounce": 0}}}
      """
    When I start the app with the arguments "main.rs"
    Then the file "chroma.log" should contain "rust 12"
    And the text "fn" should have a color
    And the text "main()" should have no color

  @setting:chroma.languages
  Scenario: A language left out of the claim is not sent to chroma
    Given the project configuration:
      """
      {"plugins": {"chroma": {"debounce": 0, "languages": "go"}}}
      """
    And I start the app with the arguments "main.rs"
    When I use the action "goto_file"
    And I type "main.go"
    And I should see "main.go" in the dialog "Go to File"
    And I press "Enter"
    Then the file "chroma.log" should contain "go 12"
    And the file "chroma.log" should not contain "rust"

  @setting:chroma.maxBytes
  Scenario: A file larger than the limit is not sent to chroma
    Given a file "big.rs" containing "fn big() { let a_long_name_to_pass_the_limit = 1; }"
    And the project configuration:
      """
      {"plugins": {"chroma": {"debounce": 0, "maxBytes": 20}}}
      """
    And I start the app with the arguments "big.rs"
    When I use the action "goto_file"
    And I type "main.rs"
    And I should see "main.rs" in the dialog "Go to File"
    And I press "Enter"
    Then the file "chroma.log" should contain "rust 12"
    And the file "chroma.log" should not contain "rust 5"

  @setting:chroma.debounce
  Scenario: Chroma runs only once the debounce has passed
    Given the project configuration:
      """
      {"plugins": {"chroma": {"debounce": 2500}}}
      """
    When I start the app with the arguments "main.rs"
    # A real clock: the debounce is what is being tested
    And I wait 1 second
    Then the file "chroma.log" should not exist
    And the file "chroma.log" should contain "rust 12"
