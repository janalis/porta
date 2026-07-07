<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\NativeDocs;

final class NativeDocsTest extends TestCase
{
    public function testPhpInternalFunctionMapsToTheManualWithDashes(): void
    {
        $this->assertSame('https://www.php.net/manual/en/function.strlen.php', NativeDocs::urlFor('php', 'strlen'));
        $this->assertSame('https://www.php.net/manual/en/function.array-map.php', NativeDocs::urlFor('php', 'array_map'), 'underscores become dashes');
    }

    public function testPhpInternalClassMapsToTheClassPage(): void
    {
        $this->assertSame('https://www.php.net/manual/en/class.datetime.php', NativeDocs::urlFor('php', 'DateTime'));
    }

    public function testPhpUserlandNamesAreNotNative(): void
    {
        $this->assertNull(NativeDocs::urlFor('php', 'composerRequire'), 'unknown function');
        $this->assertNull(NativeDocs::urlFor('php', 'TestCase'), 'unloaded vendor class (no autoload)');
        $this->assertNull(NativeDocs::urlFor('php', self::class), 'loaded but userland class fails isInternal');
    }

    public function testVariablesAndEmptyWordsAreNeverNative(): void
    {
        $this->assertNull(NativeDocs::urlFor('php', '$strlen'));
        $this->assertNull(NativeDocs::urlFor('php', ''));
        $this->assertNull(NativeDocs::urlFor(null, 'strlen'), 'unknown language');
    }

    public function testJavascriptBuiltinsAndWebApisResolveToMdn(): void
    {
        $this->assertSame(
            'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON',
            NativeDocs::urlFor('javascript', 'JSON'),
        );
        $this->assertSame(
            'https://developer.mozilla.org/en-US/docs/Web/API/Window/fetch',
            NativeDocs::urlFor('javascript', 'fetch'),
        );
        $this->assertNull(NativeDocs::urlFor('javascript', 'myHelper'), 'not in the curated list');
    }

    public function testTypescriptSharesTheJavascriptMap(): void
    {
        $this->assertSame(NativeDocs::urlFor('javascript', 'Promise'), NativeDocs::urlFor('typescript', 'Promise'));
    }

    public function testCssPropertiesResolveForCssAndScss(): void
    {
        $this->assertSame('https://developer.mozilla.org/en-US/docs/Web/CSS/display', NativeDocs::urlFor('css', 'display'));
        $this->assertSame('https://developer.mozilla.org/en-US/docs/Web/CSS/grid', NativeDocs::urlFor('scss', 'grid'));
        $this->assertNull(NativeDocs::urlFor('css', 'shiny'), 'not a curated property');
    }

    public function testPythonBuiltinsAnchorIntoTheFunctionsPage(): void
    {
        $this->assertSame('https://docs.python.org/3/library/functions.html#len', NativeDocs::urlFor('python', 'len'));
        $this->assertNull(NativeDocs::urlFor('python', 'my_func'));
    }

    public function testLanguagesWithoutAMapYieldNothing(): void
    {
        $this->assertNull(NativeDocs::urlFor('bash', 'echo'));
        $this->assertNull(NativeDocs::urlFor('yaml', 'anchors'));
    }
}
