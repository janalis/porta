Feature: Test coverage
  Running all the tests with coverage marks, in the change strip of each
  file the run measured, every statement a test ran and every one no test
  reached. Hide Coverage takes the bars away. PHPUnit here is a stand-in
  whose coverage report is the project's coverage.xml.

  Background:
    Given a PHP project
    And the bundled plugins are available
    And only PHP is on the PATH
    And a recording stand-in "vendor/bin/phpunit" for "fake-phpunit"
    And a file "tests/CalcTest.php" containing:
      """
      <?php
      final class CalcTest extends \PHPUnit\Framework\TestCase
      {
          public function testAdds(): void {}
      }
      """
    And a file "src/Calc.php" containing:
      """
      <?php

      final class Calc
      {
          public function add(int $a, int $b): int
          {
              return $a + $b;
          }

          public function sub(int $a, int $b): int
          {
              return $a - $b;
          }
      }
      """
    And a file "coverage.xml" containing:
      """
      <coverage><project><file name="{root}/src/Calc.php"><line num="7" type="stmt" count="1"/><line num="12" type="stmt" count="0"/></file></project></coverage>
      """

  @command:phpunit:run_with_coverage @command:phpunit:hide_coverage
  Scenario: Coverage marks what ran and what did not, until it is hidden
    Given I start the app with the arguments "src/Calc.php --plugin=phpunit"
    When I find the action "PHPUnit: Run All Tests with Coverage"
    And I press "Enter"
    Then line 7 should be marked covered in the coverage strip
    And line 12 should be marked uncovered in the coverage strip
    And line 3 should be marked none in the coverage strip
    And the file "vendor/bin/phpunit.calls" should contain "--coverage-clover"
    When I find the action "PHPUnit: Hide Coverage"
    And I press "Enter"
    Then the coverage strip should be empty
