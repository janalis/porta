Feature: Searching vendor/
  vendor/ is left out of Go to File, so dependencies never crowd out your own
  files, until "Search vendor/ files" lets them in.

  Background:
    Given a PHP project
    And a file "src/Kernel.php" containing "<?php"
    And a file "vendor/acme/lib/Librarian.php" containing "<?php"

  @setting:searchIncludeVendor
  Scenario: By default Go to File does not find a vendored file
    Given I start the app with the arguments "src/Kernel.php"
    When I use the action "goto_file"
    And I type "Librarian"
    Then I should see "Go to File"
    And I should not see "Librarian.php"

  @setting:searchIncludeVendor
  Scenario: With vendor/ searched, Go to File finds it
    Given the project configuration:
      """
      {"settings": {"searchIncludeVendor": true}}
      """
    And I start the app with the arguments "src/Kernel.php"
    When I use the action "goto_file"
    And I type "Librarian"
    Then I should see "Librarian.php" in the dialog "Go to File"
    When I press "Enter"
    Then the editor should show "vendor/acme/lib/Librarian.php"
