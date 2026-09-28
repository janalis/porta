<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * phpunit.xml.dist fails the run on every issue kind but one: a skipped test,
 * which skipWithoutGit()/skipWithoutBinary() make load-bearing on a machine
 * without the wrapped tool. failOnAllIssues would say "every kind, now and
 * later" in one flag, but it counts skips and overrides failOnSkipped, so the
 * kinds are listed instead — and this test is what keeps "and later" true.
 */
final class PhpunitConfigTest extends TestCase
{
    /** Kinds the list deliberately leaves out, each with its reason. */
    private const array NOT_FAILED_ON = [
        'failOnAllIssues' => 'it includes skipped tests and overrides failOnSkipped',
        'failOnSkipped' => 'a missing tool skips its test on purpose',
        'failOnDirectDeprecation' => 'failOnDeprecation covers it',
        'failOnIndirectDeprecation' => 'failOnDeprecation covers it',
        'failOnSelfDeprecation' => 'failOnDeprecation covers it',
    ];

    public function testEveryIssueKindButSkippedFailsTheRun(): void
    {
        $root = \dirname(__DIR__, levels: 2);
        \preg_match_all('/name="(failOn[A-Za-z]+)"/', (string) \file_get_contents($root.'/vendor/phpunit/phpunit/phpunit.xsd'), $schema);
        $config = \simplexml_load_file($root.'/phpunit.xml.dist');
        \assert(false !== $config, description: 'phpunit.xml.dist parses');

        $kinds = \array_values(\array_diff(\array_unique($schema[1]), \array_keys(self::NOT_FAILED_ON)));
        $this->assertNotSame([], $kinds, 'the premise: the schema lists failOn* kinds');
        foreach ($kinds as $kind) {
            $this->assertSame('true', (string) $config[$kind], $kind.' must fail the run — a kind PHPUnit added since the list was written?');
        }
        foreach (\array_keys(self::NOT_FAILED_ON) as $kind) {
            $this->assertNotSame('true', (string) $config[$kind], $kind.': '.self::NOT_FAILED_ON[$kind]);
        }
    }
}
