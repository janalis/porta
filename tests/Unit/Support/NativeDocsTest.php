<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\NativeDocs;

final class NativeDocsTest extends TestCase
{
    public function testPhpInternalFunctionMapsToTheManualWithDashes(): void
    {
        $this->assertSame('https://www.php.net/manual/en/function.strlen.php', NativeDocs::urlFor('php', word: 'strlen'));
        $this->assertSame('https://www.php.net/manual/en/function.array-map.php', NativeDocs::urlFor('php', word: 'array_map'), 'underscores become dashes');
    }

    public function testPhpInternalClassMapsToTheClassPage(): void
    {
        $this->assertSame('https://www.php.net/manual/en/class.datetime.php', NativeDocs::urlFor('php', word: 'DateTime'));
    }

    public function testPhpUserlandNamesAreNotNative(): void
    {
        $this->assertNull(NativeDocs::urlFor('php', word: 'composerRequire'), 'unknown function');
        $this->assertNull(NativeDocs::urlFor('php', word: 'TestCase'), 'unloaded vendor class (no autoload)');
        $this->assertNull(NativeDocs::urlFor('php', self::class), 'loaded but userland class fails isInternal');
    }

    public function testVariablesAndEmptyWordsAreNeverNative(): void
    {
        $this->assertNull(NativeDocs::urlFor('php', word: '$strlen'));
        $this->assertNull(NativeDocs::urlFor('php', word: ''));
        $this->assertNull(NativeDocs::urlFor(null, word: 'strlen'), 'unknown language');
    }

    public function testJavascriptBuiltinsAndWebApisResolveToMdn(): void
    {
        $this->assertSame(
            'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON',
            NativeDocs::urlFor('javascript', word: 'JSON'),
        );
        $this->assertSame(
            'https://developer.mozilla.org/en-US/docs/Web/API/Window/fetch',
            NativeDocs::urlFor('javascript', word: 'fetch'),
        );
        $this->assertNull(NativeDocs::urlFor('javascript', word: 'myHelper'), 'not in the curated list');
    }

    public function testTypescriptSharesTheJavascriptMap(): void
    {
        $this->assertSame(NativeDocs::urlFor('javascript', word: 'Promise'), NativeDocs::urlFor('typescript', word: 'Promise'));
    }

    public function testCssPropertiesResolveForCssAndScss(): void
    {
        $this->assertSame('https://developer.mozilla.org/en-US/docs/Web/CSS/display', NativeDocs::urlFor('css', word: 'display'));
        $this->assertSame('https://developer.mozilla.org/en-US/docs/Web/CSS/grid', NativeDocs::urlFor('scss', word: 'grid'));
        $this->assertNull(NativeDocs::urlFor('css', word: 'shiny'), 'not a curated property');
    }

    public function testPythonBuiltinsAnchorIntoTheFunctionsPage(): void
    {
        $this->assertSame('https://docs.python.org/3/library/functions.html#len', NativeDocs::urlFor('python', word: 'len'));
        $this->assertNull(NativeDocs::urlFor('python', word: 'my_func'));
    }

    public function testLanguagesWithoutAMapYieldNothing(): void
    {
        $this->assertNull(NativeDocs::urlFor('bash', word: 'echo'));
        $this->assertNull(NativeDocs::urlFor('yaml', word: 'anchors'));
    }
}
