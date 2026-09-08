<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;

/**
 * `composer verify` and CI must grade the same tree.
 *
 * The chain cannot drift from the gates themselves — it is a composer script
 * array, so it IS the scripts it names — but it can drift from CI, which calls
 * them one by one across five jobs. That split is deliberate: the `tests` job
 * runs twice over an intl matrix, `coverage` needs a pcov runner where `static`
 * needs none, and folding them into one `verify` job would serialise what CI
 * runs in parallel. `testing.md`'s flake doctrine also reads the divergence
 * between the `tests` and `coverage` jobs as its machine-load tell.
 *
 * So the two lists are kept identical by a ratchet rather than by a promise,
 * in the arch gate's doctrine: it fails in EITHER direction. A gate wired into
 * CI and not into `verify` is a gate a developer never runs; one in `verify`
 * and not in CI is a gate that only blocks the person who remembered.
 */
final class VerifyGatesMatchCiTest extends TestCase
{
    /**
     * Run by CI on purpose and NOT by `composer verify`.
     *
     * `test` is subsumed — `coverage:check` runs the same suite under pcov, so
     * running both locally ran it twice. CI keeps its own `tests` job because
     * comparing it against `coverage` is how the flake doctrine tells a
     * machine-load failure from a real one, and because it is the leg that runs
     * over the intl matrix.
     *
     * The rest are not gates: they install, validate the manifest, or audit.
     *
     * @var list<string>
     */
    private const array NOT_IN_VERIFY = ['test', 'install', 'validate', 'audit'];

    public function testEveryGateCiRunsIsInVerifyAndTheOtherWayRound(): void
    {
        $root = \dirname(__DIR__, levels: 3);

        /** @var array{scripts: array<string, list<string>|string>} $composer */
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), associative: true);
        $verify = self::flatten($composer['scripts'], 'verify');

        preg_match_all('/^\s*(?:- )?run: composer ([a-z:]+)/m', (string) file_get_contents($root.'/.github/workflows/ci.yml'), $matches);
        $ci = array_values(array_unique(array_diff($matches[1], self::NOT_IN_VERIFY)));

        sort($verify);
        sort($ci);
        $this->assertSame($ci, $verify, 'composer verify and CI must run the same gates — add the new one to both, or say here why not');
    }

    /**
     * The gates a chain names, following `@verify…` references into the nested
     * chain and stopping at everything else.
     *
     * Stopping matters: `coverage:check` is itself a four-step chain, and
     * recursing into it would report its internals — `@coverage`, two
     * `bin/porta-dev` calls — as gates. CI invokes `composer coverage:check`,
     * so that name is the leaf on both sides.
     *
     * @param array<string, list<string>|string> $scripts
     *
     * @return list<string>
     */
    private static function flatten(array $scripts, string $name): array
    {
        $steps = $scripts[$name] ?? [];
        $out = [];
        foreach (\is_array($steps) ? $steps : [$steps] as $step) {
            if (!str_starts_with($step, '@')) {
                continue; // a shell command, not a reference to another gate
            }
            $referenced = substr($step, offset: 1);
            $out = [...$out, ...(str_starts_with($referenced, 'verify') ? self::flatten($scripts, $referenced) : [$referenced])];
        }

        return $out;
    }
}
