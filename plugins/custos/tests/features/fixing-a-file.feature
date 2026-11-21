Feature: Fixing a file with custos
  Custos: Fix File runs `custos fix` on the active file and puts the result in
  the buffer, one undo away. The fixer works on the file on disk, so a buffer
  with unsaved changes is refused. The Target PHP version and Comparison style
  settings travel to the fixer, as they do to the language server. The binary
  is the one the setting names, else the PATH, else ~/.porta/bin.

  Background:
    Given a PHP project
    And the bundled plugin "custos" is enabled
    And only the stand-ins are on the PATH
    And a file "a.php" containing:
      """
      <?php
      $x = 1;
      """

  Scenario: Fixing puts the fixed file in the buffer, one undo away
    Given a stand-in "custos" on the PATH doing:
      """
      require __DIR__.'/../custos-stand-in.php';
      """
    And a file "custos-stand-in.php" containing:
      """
      <?php
      // `custos lsp` waits on its input and answers nothing; `custos fix` says what it was told
      if ('lsp' === $argv[1]) {
          while (false !== fgets(STDIN));
          exit(0);
      }
      $file = $argv[count($argv) - 1];
      $flags = array_values(array_filter(array_slice($argv, 4, -1)));
      file_put_contents($file, file_get_contents($file).'// custos fixed'.('' === implode(' ', $flags) ? '' : ' with '.implode(' ', $flags))."\n");
      """
    And I start the app with the arguments "a.php"
    When I use the action "custos:fix"
    Then the editor should contain "// custos fixed"
    And I should see "custos: fixed a.php"
    When I use the action "undo"
    Then the editor should not contain "// custos fixed"

  Scenario: A buffer with unsaved changes is refused, as the fix runs on the file on disk
    Given a stand-in "custos" on the PATH doing:
      """
      if ('lsp' === $argv[1]) { while (false !== fgets(STDIN)); exit(0); }
      file_put_contents($argv[count($argv) - 1], "<?php\n// custos fixed\n");
      """
    And I start the app with the arguments "a.php"
    When I type "// unsaved"
    And I use the action "custos:fix"
    Then I should see "custos: save a.php first"
    And the file "a.php" should not contain "custos fixed"

  @setting:custos.php
  Scenario: The Target PHP version is passed to the fixer
    Given a stand-in "custos" on the PATH doing:
      """
      if ('lsp' === $argv[1]) { while (false !== fgets(STDIN)); exit(0); }
      $file = $argv[count($argv) - 1];
      file_put_contents($file, file_get_contents($file).'// custos fixed with '.implode(' ', array_slice($argv, 4, -1))."\n");
      """
    And the plugin setting "custos.php" is "8.3"
    And I start the app with the arguments "a.php"
    When I use the action "custos:fix"
    Then the editor should contain "// custos fixed with --php 8.3"

  @setting:custos.comparisonStyle
  Scenario: The Comparison style chosen on its Settings page is passed to the fixer
    Given a stand-in "custos" on the PATH doing:
      """
      if ('lsp' === $argv[1]) { while (false !== fgets(STDIN)); exit(0); }
      $file = $argv[count($argv) - 1];
      file_put_contents($file, file_get_contents($file).'// custos fixed with '.implode(' ', array_slice($argv, 4, -1))."\n");
      """
    And I start the app with the arguments "a.php"
    When I open the Settings page "custos"
    And I choose "yoda" for the setting "Comparison style"
    Then the project configuration should have "plugins.custos.comparisonStyle" set to "yoda"
    When I press "Escape"
    And I use the action "custos:fix"
    Then the editor should contain "// custos fixed with --comparison-style yoda"

  @setting:custos.binary
  Scenario: The custos binary setting names the executable that runs
    Given only the stand-ins are on the PATH
    And an executable "tools/my-custos" doing:
      """
      if ('lsp' === $argv[1]) { while (false !== fgets(STDIN)); exit(0); }
      $file = $argv[count($argv) - 1];
      file_put_contents($file, file_get_contents($file)."// fixed by the named binary\n");
      """
    And the plugin setting "custos.binary" is "%workspace%/tools/my-custos"
    And I start the app with the arguments "a.php"
    When I use the action "custos:fix"
    Then the editor should contain "// fixed by the named binary"
