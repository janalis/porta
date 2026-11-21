Feature: Running PHPUnit tests
  The phpunit plugin runs the test at the caret, the tests of a file, the
  whole suite, or again only what failed. The results land in the Tests tab,
  failures first; each test method is marked at the end of its line, and the
  status bar counts what fails. PHPUnit here is a stand-in that fails every
  method whose name holds "Fails", and records how it was called.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And only PHP is on the PATH
    And a recording stand-in "vendor/bin/phpunit" for "fake-phpunit"
    And a file "tests/DemoTest.php" containing:
      """
      <?php

      namespace App\Tests;

      final class DemoTest extends \PHPUnit\Framework\TestCase
      {
          public function testPasses(): void
          {
              $this->assertTrue(true);
          }

          public function testFails(): void
          {
              $this->assertTrue(false);
          }
      }
      """
    And a file "tests/OtherTest.php" containing:
      """
      <?php

      namespace App\Tests;

      final class OtherTest extends \PHPUnit\Framework\TestCase
      {
          public function testAlsoFails(): void
          {
              $this->assertTrue(false);
          }
      }
      """

  @command:phpunit:run_file
  Scenario: Running the tests of a file marks each method and lists the failures first
    Given I start the app in a 120x44 terminal with the arguments "tests/DemoTest.php --plugin=phpunit"
    When I find the action "PHPUnit: Run Tests in File"
    And I press "Enter"
    Then I should see "✗ App\Tests\DemoTest::testFails"
    And I should see "✓ App\Tests\DemoTest::testPasses"
    And I should not see "OtherTest"
    And I should see "✓ test passed"
    And I should see "✗ test failed"
    And I should see "✗ 1 test failing"

  @command:phpunit:run_all
  Scenario: Running all the tests runs every file
    Given I start the app with the arguments "tests/DemoTest.php --plugin=phpunit"
    When I find the action "PHPUnit: Run All Tests"
    And I press "Enter"
    Then I should see "✗ App\Tests\OtherTest::testAlsoFails"
    And I should see "✗ App\Tests\DemoTest::testFails"
    And I should see "✗ 2 tests failing"

  @command:phpunit:run_at_caret
  Scenario: Running the test at the caret runs that method alone
    Given I start the app with the arguments "tests/DemoTest.php --plugin=phpunit"
    When I put the caret on line 9
    And I use the action "phpunit:run_at_caret"
    Then I should see "✓ App\Tests\DemoTest::testPasses"
    And I should not see "testFails"
    And the file "vendor/bin/phpunit.calls" should contain "--filter"

  @command:phpunit:rerun_failed
  Scenario: Rerunning the failed tests runs only those
    Given I start the app with the arguments "tests/DemoTest.php --plugin=phpunit"
    When I find the action "PHPUnit: Run All Tests"
    And I press "Enter"
    Then I should see "✗ 2 tests failing"
    When I find the action "PHPUnit: Rerun Failed Tests"
    And I press "Enter"
    Then I should see "2 results"
    And I should see "✗ App\Tests\OtherTest::testAlsoFails"
    And I should not see "✓ App\Tests\DemoTest::testPasses"

  @setting:phpunit.binary
  Scenario: The phpunit binary the settings name is what runs
    Given I delete "vendor/bin/phpunit"
    And a recording stand-in "tools/phpunit" for "fake-phpunit"
    And the project configuration naming the test fakes:
      """
      {"plugins": {"phpunit": {"binary": "%workspace%/tools/phpunit"}}}
      """
    And I start the app with the arguments "tests/DemoTest.php --plugin=phpunit"
    When I find the action "PHPUnit: Run Tests in File"
    And I press "Enter"
    Then I should see "✗ App\Tests\DemoTest::testFails"
    And the file "tools/phpunit.calls" should contain "--log-junit"
