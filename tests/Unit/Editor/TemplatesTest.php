<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\TemplateCatalog;
use Porta\Editor\Templates;

final class TemplatesTest extends TestCase
{
    public function testWithNoProjectConfigTheBuiltInsAreWhatYouGet(): void
    {
        $this->assertSame(TemplateCatalog::forLanguage('php'), new Templates()->forLanguage('php'));
    }

    public function testAProjectTemplateIsAddedAlongsideTheBuiltIns(): void
    {
        $templates = new Templates(['php' => ['templates' => ['dd' => 'dd($1);$0']]]);

        $resolved = $templates->forLanguage('php');
        $this->assertSame('dd($1);$0', $resolved['dd']);
        $this->assertArrayHasKey('fore', $resolved, 'the built-ins are still there');
    }

    public function testAProjectTemplateReplacesTheBuiltInOfTheSameName(): void
    {
        $templates = new Templates(['php' => ['templates' => ['fore' => 'MINE']]]);

        $this->assertSame('MINE', $templates->forLanguage('php')['fore']);
    }

    /**
     * An empty body REMOVES a built-in. There is no settings row for a map, so
     * this is the only way to stop a shipped abbreviation expanding over
     * something you type as ordinary code.
     */
    public function testAnEmptyBodySwitchesABuiltInOff(): void
    {
        $templates = new Templates(['php' => ['templates' => ['fore' => '']]]);

        $resolved = $templates->forLanguage('php');
        $this->assertArrayNotHasKey('fore', $resolved);
        $this->assertArrayHasKey('forek', $resolved, 'only the named one goes');
    }

    public function testAnotherLanguagesTemplatesDoNotLeakIn(): void
    {
        $templates = new Templates(['javascript' => ['templates' => ['jsonly' => 'X']]]);

        $this->assertArrayNotHasKey('jsonly', $templates->forLanguage('php'));
        $this->assertArrayHasKey('jsonly', $templates->forLanguage('javascript'));
    }

    /** A language block with no templates key at all is not an error. */
    public function testALanguageBlockWithoutTemplatesIsHarmless(): void
    {
        $templates = new Templates(['php' => []]);

        $this->assertSame(TemplateCatalog::forLanguage('php'), $templates->forLanguage('php'));
    }
}
