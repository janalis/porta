<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

/**
 * Reads the report `--coverage-clover` writes: for each file the run
 * measured, which statement lines ran. Only `stmt` lines count — a `method`
 * line is a signature, which is not code that runs.
 */
final class CloverReport
{
    /**
     * @return array<string, array<int, bool>> file as PHPUnit names it (its real path) => 0-based line => whether it ran
     *
     * @throws \RuntimeException when $xml is not a Clover report
     */
    public static function parse(string $xml): array
    {
        $files = [];
        foreach (Xml::all(Xml::load($xml, 'coverage', 'Clover report'), '//file') as $file) {
            $lines = [];
            foreach (Xml::all($file, 'line[@type="stmt"]') as $line) {
                $lines[(int) $line['num'] - 1] = (int) $line['count'] > 0;
            }
            $files[(string) $file['name']] = $lines;
        }

        return $files;
    }
}
