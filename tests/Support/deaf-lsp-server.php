<?php

declare(strict_types=1);

/**
 * A "wedged" LSP server for the freeze-regression tests: it never reads its
 * stdin, so the OS pipe buffer fills and any blocking client write would hang
 * forever. Bounded lifetime so a leaked child cannot outlive the test run.
 */
for ($i = 0; $i < 30; ++$i) {
    sleep(1);
}
