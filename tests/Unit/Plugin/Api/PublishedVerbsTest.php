<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PublishedLayer;

/**
 * Every verb a plugin puts something on screen with, accounted for.
 *
 * Switching a plugin off takes back what it published only through
 * {@see \Porta\Plugin\Api\PublishedLayers}, and only what a verb RECORDED
 * there: a new `set…` that writes a sink and forgets `record()` is a plugin
 * that leaves its marks frozen on screen once it is off — the defect the
 * layers exist to end, back for one verb, and nothing fails. So each such
 * verb is classified here, and a new one fails this test until someone says
 * which layer takes it back, or why none has to.
 */
final class PublishedVerbsTest extends TestCase
{
    /** The verbs that publish a layer, and the layer that takes it back. */
    private const array LAYERED = [
        'setDiagnostics' => PublishedLayer::Diagnostics,
        'setChangeMarks' => PublishedLayer::ChangeMarks,
        'setCommentMarks' => PublishedLayer::CommentMarks,
        'setCoverage' => PublishedLayer::Coverage,
        'setFileStatuses' => PublishedLayer::FileStatuses,
        'showChangeMarkGutter' => PublishedLayer::ChangeGutter,
        'setLineAnnotations' => PublishedLayer::LineAnnotations,
        'setLineBlocks' => PublishedLayer::LineBlocks,
    ];

    /** The verbs that look like publishing and need no layer — each with why. */
    private const array UNLAYERED = [
        'showList' => 'a list tool\'s rows live in its own tab, which goes with its registration',
        'updateList' => 'the same tab as showList',
    ];

    public function testEveryPublishingVerbIsClassified(): void
    {
        $verbs = [];
        foreach (new \ReflectionClass(PluginApi::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (1 !== \preg_match('/^(set|show|update)[A-Z]/', $method->getName())) {
                continue;
            }
            $verbs[] = $method->getName();
        }
        $classified = [...\array_keys(self::LAYERED), ...\array_keys(self::UNLAYERED)];
        \sort($verbs);
        \sort($classified);

        $this->assertSame($classified, $verbs, 'a new publishing verb: record() its layer and add it to LAYERED, or say in UNLAYERED why nothing need take it back');
    }

    public function testEachLayerHasOneVerbAndEachLayeredVerbRecordsItsLayer(): void
    {
        $this->assertSame(PublishedLayer::cases(), \array_values(self::LAYERED), 'one verb per layer, every layer covered');
        $source = (string) \file_get_contents((string) new \ReflectionClass(PluginApi::class)->getFileName());
        foreach (self::LAYERED as $verb => $layer) {
            $method = new \ReflectionClass(PluginApi::class)->getMethod($verb); // a public verb's lines, not private state
            $body = \implode("\n", \array_slice(\explode("\n", $source), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $this->assertStringContainsString('->published->record(PublishedLayer::'.$layer->name, $body, $verb.'() publishes without recording it: switched off, the plugin would leave it on screen');
        }
    }
}
