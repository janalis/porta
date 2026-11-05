<?php

declare(strict_types=1);

namespace Porta\Composer;

/**
 * Where a package is required in a composer.json's text: the line of its
 * entry in `require` or `require-dev`, and the package a line requires.
 *
 * Read from the text rather than decoded, because what is wanted is a LINE —
 * the one the caret is on, the one a mark goes after — and decoding loses
 * them. Only a key directly inside one of those two objects counts: the same
 * name as an `allow-plugins` key, or a key with a slash in `extra`, is not a
 * requirement.
 */
final class Manifest
{
    private const array SECTIONS = ['require', 'require-dev'];

    /** @var array<int, string> 0-based line => the package it requires */
    private array $packages = [];

    public function __construct(string $text)
    {
        $depth = 0;
        $section = null;
        $match = [];
        foreach (\explode("\n", $text) as $line => $row) {
            $key = 1 === \preg_match('~^\s*"([^"\\\\]+)"\s*:~', $row, $match) ? $match[1] : null;
            if (1 === $depth && null !== $key) {
                $section = $key;
            }
            if (2 === $depth && null !== $key && \in_array($section, self::SECTIONS, strict: true) && \str_contains($key, '/')) {
                $this->packages[$line] = \strtolower($key);
            }
            $depth += self::nesting($row);
        }
    }

    /** The 0-based line requiring $package, or null when the file does not require it. */
    public function lineOf(string $package): ?int
    {
        $line = \array_search(\strtolower($package), $this->packages, strict: true);

        return false === $line ? null : $line;
    }

    /** The package line $line requires, or null when it requires none. */
    public function packageAt(int $line): ?string
    {
        return $this->packages[$line] ?? null;
    }

    /** How much deeper $row leaves the document: its opening brackets less its closing ones, strings aside. */
    private static function nesting(string $row): int
    {
        $outside = (string) \preg_replace('~"(?:[^"\\\\]|\\\\.)*"~', replacement: '""', subject: $row);

        return \substr_count($outside, needle: '{') + \substr_count($outside, needle: '[') - \substr_count($outside, needle: '}') - \substr_count($outside, needle: ']');
    }
}
