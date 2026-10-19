<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidYamlScalars;

final class MermaidYamlScalarsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function normalization(): iterable
    {
        yield 'multiple scalars' => ['{alias: 2026-10-17, type: 2001-12-15}', '{alias: !!str 2026-10-17, type: !!str 2001-12-15}'];
        yield 'anchor' => ['alias: &date 2026-10-17', 'alias: &date !!str 2026-10-17'];
        yield 'spaced quoted key' => ["'alias' :2026-10-17", "'alias' :!!str 2026-10-17"];
        yield 'date comment' => ["alias: 2026-10-17 # date\n", "alias: !!str 2026-10-17 # date\n"];
        yield 'unterminated quoted key' => ['"alias: 2026-10-17', '"alias: 2026-10-17'];
        yield 'explicit tag' => ['alias: !!str 2026-10-17', 'alias: !!str 2026-10-17'];
        yield 'timestamp outside value' => ['2026-10-17: alias', '2026-10-17: alias'];
        yield 'plain scalar colon' => ['alias: log:2026-10-17', 'alias: log:2026-10-17'];
        yield 'date suffix' => ['alias: 2026-10-17 release', 'alias: 2026-10-17 release'];
        yield 'comment only' => ["# alias: 2026-10-17\nalias: text", "# alias: 2026-10-17\nalias: text"];
        yield 'flow delimiters' => ['{alias: [2026-10-17]}', '{alias: [2026-10-17]}'];
        yield 'anchored literal block' => ["alias: &text |-\n  alias: 2026-10-17\n", "alias: &text |-\n  alias: 2026-10-17\n"];
        yield 'literal block' => ["alias: |-\n  alias: 2026-10-17\n  # literal\ntype: actor\n", "alias: |-\n  alias: 2026-10-17\n  # literal\ntype: actor\n"];
        yield 'escaped quote' => ['alias: "say \"alias: 2026-10-17\""', 'alias: "say \"alias: 2026-10-17\""'];
    }

    #[DataProvider('normalization')]
    public function testOnlyPlainTimestampValuesReceiveStringTags(string $yaml, string $expected): void
    {
        $this->assertSame($expected, MermaidYamlScalars::preserveDates($yaml));
    }
}
