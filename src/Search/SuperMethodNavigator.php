<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BreadcrumbScanner;
use Porta\Support\ProjectPaths;

/**
 * PhpStorm-style Go to Super Method (Ctrl+U): from a method, jump to the
 * declaration it overrides — in a parent class, an interface it implements, or
 * a trait it uses.
 *
 * No language server answers this. `textDocument/implementation` walks the
 * other way (down to implementors) and neither phpantom nor phpactor advertises
 * a type hierarchy at all (`php-lsp-capabilities`), so this is Porta doing what
 * its backends cannot rather than a wrapper over one that could.
 *
 * The resolution is deliberately the same trick {@see TestNavigator} uses: a
 * parent's short name is its FILE name in any PSR-4 project, so the shared
 * {@see FileIndex} finds it without an index of our own and without parsing
 * `composer.json`. That is why only the LAST segment of a parent name is ever
 * read — `\App\Base\Foo`, `Base\Foo` and an aliased `Foo` all resolve to
 * `Foo.php`, and a project with two `Foo.php` gets both candidates tried in
 * path order rather than a guess.
 *
 * What it does not do: resolve which of two same-named classes an import meant.
 * That fails as "no super method found", never as a wrong jump. Whether
 * vendored parents are reachable at all is the shared index's scope, which the
 * "Search vendor/ files" setting owns.
 */
final class SuperMethodNavigator
{
    /** Deep enough for any hierarchy anyone reads by hand; a cycle would stop here anyway. */
    private const int MAX_DEPTH = 8;

    public function __construct(
        private readonly FileIndex $files,
        private readonly ProjectPaths $paths,
    ) {}

    /**
     * The declaration the method at $line overrides, or null when there is none.
     *
     * @param string $source    the buffer's text, not the file's — an unsaved
     *                          rename must be searched for as it now reads
     * @param int    $caretLine 0-based, as the editor counts
     *
     * @return array{path: string, line: int, label: string}|null project-relative path, 0-based line
     */
    public function locate(string $source, int $caretLine): ?array
    {
        $scopes = BreadcrumbScanner::scan($source);
        $method = null;
        $type = null;
        foreach ($scopes as $scope) {
            if ($scope['startLine'] > ($caretLine + 1) || $scope['endLine'] < ($caretLine + 1)) {
                continue;
            }
            if ('function' === $scope['kind']) {
                $method = $scope['label'];
            }
            if ('type' === $scope['kind']) {
                $type = $scope['label'];
            }
        }
        if (null === $method || null === $type) {
            return null;
        }

        return $this->climb(self::parentsOf($source, $type), $method, [], 0);
    }

    /**
     * Breadth-first through the parents, then their parents: the nearest
     * declaration wins, which is the one an override actually replaces.
     *
     * @param list<string>        $parents short names
     * @param array<string, true> $seen    files already searched, so a cycle in a
     *                                     mistyped hierarchy cannot spin
     *
     * @return array{path: string, line: int, label: string}|null
     */
    private function climb(array $parents, string $method, array $seen, int $depth): ?array
    {
        if ($depth >= self::MAX_DEPTH || [] === $parents) {
            return null;
        }

        $next = [];
        foreach ($parents as $parent) {
            foreach ($this->candidates($parent) as $relative) {
                if (\array_key_exists($relative, $seen)) {
                    continue;
                }
                $seen[$relative] = true;
                $source = $this->read($relative);
                if (null === $source) {
                    continue;
                }
                $line = self::methodLine($source, $method);
                if (null !== $line) {
                    return ['path' => $relative, 'line' => $line, 'label' => $parent.'::'.$method];
                }
                $next = [...$next, ...self::parentsOf($source, $parent)];
            }
        }

        return $this->climb($next, $method, $seen, $depth + 1);
    }

    /**
     * Project-relative files that could hold $name.
     *
     * The scope is the shared index's, which means the user's "Search vendor/
     * files" setting decides whether a framework's abstract class is a place
     * this jumps to. Filtering vendor here as well would be a second, quieter
     * answer to a question the settings page already asks — and the wrong one:
     * the parent you want is often the framework's.
     *
     * @return list<string>
     */
    private function candidates(string $name): array
    {
        $target = $name.'.php';
        $paths = [];
        foreach ($this->files->entries() as $entry) {
            if (basename($entry['path']) !== $target) {
                continue;
            }
            $paths[] = $entry['path'];
        }

        return $paths;
    }

    /** The file's text, or null when it cannot be read. */
    private function read(string $relative): ?string
    {
        $absolute = $this->paths->absolute($relative);
        $source = is_file($absolute) ? file_get_contents($absolute) : false;

        return false === $source ? null : $source;
    }

    /**
     * The 0-based line declaring $method, or null when the source has no such
     * function.
     *
     * Tokens rather than {@see BreadcrumbScanner}, which reports scopes and a
     * scope needs a body: an INTERFACE method and an abstract one have none,
     * and those are exactly the declarations an override points at.
     */
    private static function methodLine(string $source, string $method): ?int
    {
        foreach (self::tokens($source) as $index => $token) {
            if (!\is_array($token) || \T_FUNCTION !== $token[0]) {
                continue;
            }
            $name = self::nextName($source, $index);
            if ($name === $method) {
                return $token[2] - 1;
            }
        }

        return null;
    }

    /**
     * The name a `function` token declares, or null for a closure.
     *
     * @return string|null
     */
    private static function nextName(string $source, int $index): ?string
    {
        $tokens = self::tokens($source);
        for ($at = $index + 1, $end = \count($tokens); $at < $end; ++$at) {
            $token = $tokens[$at];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], strict: true)) {
                continue;
            }

            return \is_array($token) && \T_STRING === $token[0] ? $token[1] : null;
        }

        return null;
    }

    /**
     * The file's tokens, memoized per source: every lookup above walks them,
     * and re-lexing a file once per candidate method is the difference between
     * a keystroke and a pause.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function tokens(string $source): array
    {
        static $cache = ['' => []];
        $key = hash('xxh128', $source);
        if (!\array_key_exists($key, $cache)) {
            set_error_handler(static fn (): bool => true);
            $cache = [$key => token_get_all($source)]; // one file at a time: this walks a hierarchy, not a project
            restore_error_handler();
        }

        /** @var list<array{0: int, 1: string, 2: int}|string> */
        return $cache[$key];
    }

    /**
     * The short names $type extends, implements, or uses as a trait.
     *
     * Short, because that is what resolves to a file: the last segment of
     * `\App\Base\Foo` is the same `Foo` an aliased import would have written.
     *
     * @return list<string>
     */
    private static function parentsOf(string $source, string $type): array
    {
        $lexemes = self::tokens($source);

        $parents = [];
        $inside = false; // between this type's name and its `{`
        $body = 0; // brace depth inside the type's body, 0 = not in it
        $collecting = false;
        $expectName = false;
        foreach ($lexemes as $lexeme) {
            if (\is_string($lexeme)) {
                if ('{' === $lexeme) {
                    $body = $inside || $body > 0 ? $body + 1 : 0;
                    $inside = false;
                    $collecting = false;
                }
                if ('}' === $lexeme && $body > 0) {
                    --$body;
                }
                continue;
            }
            [$id, $text] = $lexeme;
            if (\in_array($id, [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], strict: true)) {
                $expectName = true;
                continue;
            }
            if ($expectName) {
                $expectName = \T_WHITESPACE === $id;
                $inside = $inside || \T_STRING === $id && $text === $type;
                continue;
            }
            // A `use` at depth 1 of the body is a trait, not an import: the
            // methods it brings are as much a super declaration as a parent's.
            if (\T_USE === $id && 1 === $body) {
                $collecting = true;
                continue;
            }
            if ($inside && \in_array($id, [\T_EXTENDS, \T_IMPLEMENTS], strict: true)) {
                $collecting = true;
                continue;
            }
            if ($collecting && \in_array($id, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], strict: true)) {
                $segments = explode('\\', $text);
                $parents[] = end($segments);
            }
            if ($collecting && \T_FUNCTION === $id) {
                $collecting = false; // a closure's `use (...)`, not a trait
            }
        }

        return array_values(array_unique($parents));
    }
}
