<?php

declare(strict_types=1);

/**
 * curl's stand-in: `fetch.php -o <file> <url>` copies the local path the URL
 * names to <file>. A URL of `slow:<path>` waits a second first; `fail` exits 22,
 * curl's code for an HTTP error.
 */
[, , $out, $url] = $argv;
if ('fail' === $url) {
    exit(22);
}
if (str_starts_with($url, 'slow:')) {
    sleep(1);
    $url = substr($url, offset: 5);
}
copy($url, $out);
