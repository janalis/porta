<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

/**
 * The pictures a README shows, in the order it shows them: `![alt](src)`,
 * `![alt][ref]` with its `[ref]: src`, and `<img src>` — each made an https
 * address, a relative one read against the README's own folder (or its
 * repository's root, for `/path`), a GitHub page of a file turned into the
 * file itself.
 *
 * What is not a picture of the plugin is left out: a badge (CI status,
 * version, downloads, coverage), a person's avatar, anything under a
 * Sponsors, Backers or Contributors heading, and anything that is not https.
 * Of GitHub's light and dark pair, the dark one is kept: a terminal is most
 * often dark, and the light one's ink would be lost on it.
 */
final class ReadmeImages
{
    /** The most pictures read from one README: a store asks for the first few. */
    public const int MAX = 6;

    /** Where a badge is drawn, or what its address says: it is a picture of a number, not of the plugin. */
    private const string BADGE = '~(?:^https://(?:img\.shields\.io|shields\.io|poser\.pugx\.org|badge\.fury\.io|badgen\.net|codecov\.io|coveralls\.io|shepherd\.dev|scrutinizer-ci\.com|insight\.symfony\.com|api\.travis-ci\.(?:org|com)|travis-ci\.(?:org|com)|circleci\.com|app\.codacy\.com|avatars\.githubusercontent\.com|github\.com/[^/]+/[^/]+/(?:actions|workflows)/)|/badges?(?:\.svg|/|\?|$)|badge\.svg)~i';

    /** A section whose pictures are of other people: its sponsors, backers, contributors. */
    private const string THANKS = '~^\s*(?:#{1,6}|<h[1-6][^>]*>)\s*.*\b(?:sponsor|backer|contributor|supporter|thank|partner)~im';

    /** A section heading of the second level or deeper: where a README's masthead ends. */
    private const string SECTION = '~^\s*(?:#{2,6}\s|<h[2-6]\b)~m';

    /** A raw GitHub file: owner, repository, reference, then its path. */
    private const string RAW = '~^(https://raw\.githubusercontent\.com/[^/]+/[^/]+/[^/]+)/~';

    /** A GitHub page of a file, or of its raw bytes: owner, repository, reference, path. */
    private const string BLOB = '~^https://github\.com/([^/]+/[^/]+)/(?:blob|raw)/(.+)$~';

    /**
     * @param string $markdown the README
     * @param string $at       where the README was read: the address relative pictures are read against
     *
     * @return list<string>
     */
    public static function in(string $markdown, string $at): array
    {
        return self::found(self::withoutThanks($markdown), $at);
    }

    /**
     * The pictures above a README's first section: its masthead — the logo
     * and banner a tool's README opens with, and none of what it shows of
     * its sponsors further down.
     *
     * @return list<string>
     */
    public static function masthead(string $markdown, string $at): array
    {
        $match = [];
        $end = 1 === \preg_match(self::SECTION, $markdown, $match, \PREG_OFFSET_CAPTURE) ? (int) $match[0][1] : \strlen($markdown);

        return self::found(\substr($markdown, offset: 0, length: $end), $at);
    }

    /** $markdown less every section headed by thanks to someone. */
    private static function withoutThanks(string $markdown): string
    {
        $kept = [];
        $skipping = 0;
        foreach (\explode("\n", $markdown) as $line) {
            $level = self::level($line);
            if ($skipping > 0 && $level > 0 && $level <= $skipping) {
                $skipping = 0;
            }
            if (0 === $skipping && $level > 0 && 1 === \preg_match(self::THANKS, $line)) {
                $skipping = $level;
            }
            if (0 === $skipping) {
                $kept[] = $line;
            }
        }

        return \implode("\n", $kept);
    }

    /** $line's heading level, markdown or HTML, or 0 when it is no heading. */
    private static function level(string $line): int
    {
        $match = [];
        if (1 === \preg_match('~^\s*(#{1,6})\s~', $line, $match)) {
            return \strlen($match[1]);
        }

        return 1 === \preg_match('~^\s*<h([1-6])\b~i', $line, $match) ? (int) $match[1] : 0;
    }

    /** @return list<string> */
    private static function found(string $markdown, string $at): array
    {
        $references = [];
        $match = [];
        \preg_match_all('~^ {0,3}\[([^\]]+)\]:\s*<?(\S+?)>?(?:\s+["\'(].*)?$~m', $markdown, $match, \PREG_SET_ORDER);
        foreach ($match as $definition) {
            $references[\mb_strtolower($definition[1])] ??= $definition[2];
        }
        $found = [];
        $images = [];
        // `![alt](src)`, `![alt][ref]` (or `[]`, or none: the alt is the reference), `<img src>`.
        \preg_match_all(
            '~!\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+["\'][^)]*)?\)|!\[([^\]]*)\](?:\[([^\]]*)\])?|<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']~i',
            $markdown,
            $images,
            \PREG_SET_ORDER,
        );
        foreach ($images as $image) {
            // A group that took no part is '', or missing at the end.
            $reference = '' !== ($image[3] ?? '') ? $image[3] : $image[2] ?? '';
            $source = match (true) {
                '' !== $image[1] => $image[1],
                '' !== ($image[4] ?? '') => $image[4],
                default => $references[\mb_strtolower($reference)] ?? '',
            };
            $url = self::resolve(\html_entity_decode($source, \ENT_QUOTES | \ENT_HTML5), $at);
            if (null === $url || 1 === \preg_match(self::BADGE, $url) || \str_ends_with($url, '#gh-light-mode-only') || \in_array($url, $found, strict: true)) {
                continue;
            }
            $found[] = $url;
            if (\count($found) === self::MAX) {
                break;
            }
        }

        return $found;
    }

    /** $source as an https address, read against $at; null when it cannot be one. */
    public static function resolve(string $source, string $at): ?string
    {
        $source = \trim($source);
        if ('' === $source || 1 === \preg_match('/[\s\p{Cc}]/u', $source) || \str_starts_with($source, '#')) {
            return null;
        }
        $url = match (true) {
            \str_starts_with($source, '//') => 'https:'.$source,
            1 === \preg_match('~^[a-z][a-z0-9+.-]*:~i', $source) => $source,
            \str_starts_with($source, '/') => self::root($at).$source,
            default => self::folder($at).'/'.$source,
        };
        $blob = [];
        if (1 === \preg_match(self::BLOB, \explode('?', $url)[0], $blob)) {
            $url = 'https://raw.githubusercontent.com/'.$blob[1].'/'.$blob[2];
        }
        $url = self::normalised($url);

        return null !== $url && \str_starts_with($url, 'https://') ? $url : null;
    }

    /** The repository's root at the README's reference, or the README's host for another address. */
    private static function root(string $at): string
    {
        $match = [];
        if (1 === \preg_match(self::RAW, $at, $match)) {
            return $match[1];
        }
        $parts = \parse_url($at);

        return 'https://'.($parts['host'] ?? '');
    }

    /** The README's folder. */
    private static function folder(string $at): string
    {
        $path = \explode('?', $at)[0];
        $slash = \strrpos($path, needle: '/');

        return false === $slash ? $path : \substr($path, offset: 0, length: $slash);
    }

    /** $url with its `.` and `..` steps walked, or null when a `..` climbs out of the host. */
    private static function normalised(string $url): ?string
    {
        $parts = \explode('://', $url, limit: 2);
        if (2 !== \count($parts)) {
            return null;
        }
        [$scheme, $rest] = $parts;
        $path = \explode('?', $rest, limit: 2)[0];
        $query = \str_contains($rest, '?') ? \substr($rest, \strlen($path)) : '';
        /** @var list<string> $steps */
        $steps = [];
        $escaped = false;
        foreach (\explode('/', $path) as $index => $step) {
            if ('..' === $step) {
                $escaped = $escaped || \count($steps) <= 1;
                \array_pop($steps);

                continue;
            }
            if (0 === $index || ('.' !== $step && '' !== $step)) {
                $steps[] = $step;
            }
        }

        return $escaped ? null : $scheme.'://'.\implode('/', $steps).$query;
    }
}
