<?php

declare(strict_types=1);

/**
 * A stand-in for composer, run in the project: it notes each call's arguments
 * in `composer.log`, answers `outdated` with `outdated.json` (or fails, with
 * none), adds what `require` names to composer.json's `require`, and exits
 * with what `exit-code` holds, 0 without one — after 30 seconds, while `sleep` exists.
 */

\file_put_contents('composer.log', \implode(' ', \array_slice($argv, offset: 1))."\n", \FILE_APPEND);
if (\is_file('sleep')) {
    \sleep(30);
}
if ('outdated' === ($argv[1] ?? '')) {
    if (!\is_file('outdated.json')) {
        exit(2);
    }
    echo \file_get_contents('outdated.json');

    exit(0);
}
if ('require' === ($argv[1] ?? '')) {
    $json = (string) \file_get_contents('composer.json');
    \file_put_contents('composer.json', \str_replace("\"require\": {\n", "\"require\": {\n        \"".$argv[2]."\": \"*\",\n", $json));
}

exit(\is_file('exit-code') ? (int) \file_get_contents('exit-code') : 0);
