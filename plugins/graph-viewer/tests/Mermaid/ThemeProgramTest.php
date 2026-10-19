<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\Theme\ThemeProgram;

final class ThemeProgramTest extends TestCase
{
    /** @param list<mixed> $expression */
    #[DataProvider('expressions')]
    public function testExpressionsFollowJavaScriptCoercion(array $expression, mixed $expected): void
    {
        $self = (object) ['text' => 'é😀', 'list' => [1, null, 2], 'object' => new \stdClass(), 'none' => null];
        new ThemeProgram([])->run([['set', ['get', ['this'], ['lit', 'out'], 0], $expression]], $self);
        $this->assertTrue(\property_exists($self, 'out'));
        if (\is_float($expected) && \is_nan($expected)) {
            $this->assertNan($self->out);

            return;
        }
        $this->assertSame($expected, $self->out);
    }

    public static function expressions(): iterable
    {
        $this_ = static fn (string $key, int $optional = 0): array => ['get', ['this'], ['lit', $key], $optional];
        yield 'boolean text' => [['bin', '+', ['lit', 'x'], ['lit', true]], 'xtrue'];
        yield 'null text' => [['bin', '+', ['lit', 'x'], ['lit', null]], 'xnull'];
        yield 'undefined text' => [['bin', '+', ['lit', 'x'], $this_('missing')], 'xundefined'];
        yield 'array text' => [['bin', '+', ['lit', 'x'], $this_('list')], 'x1,,2'];
        yield 'object text' => [['bin', '+', $this_('object'), ['lit', 1]], '[object Object]1'];
        yield 'number text' => [['bin', '+', ['lit', 'pie'], ['bin', '+', ['lit', 1], ['lit', 1]]], 'pie2'];
        yield 'empty string number' => [['bin', '-', ['lit', ''], ['lit', 1]], -1.0];
        yield 'padded infinity' => [['bin', '-', ['lit', ' -Infinity '], ['lit', 1]], -\INF];
        yield 'infinity' => [['unary', '+', ['lit', 'Infinity']], \INF];
        yield 'lower infinity' => [['unary', '+', ['lit', 'infinity']], \NAN];
        yield 'exponent' => [['unary', '+', ['lit', '1e3']], 1000.0];
        yield 'hexadecimal' => [['unary', '+', ['lit', '0x1f']], 31.0];
        yield 'text' => [['unary', '+', ['lit', 'abc']], \NAN];
        yield 'true number' => [['bin', '*', ['lit', true], ['lit', 3]], 3.0];
        yield 'null number' => [['bin', '*', ['lit', null], ['lit', 3]], 0.0];
        yield 'object number' => [['unary', '-', $this_('object')], \NAN];
        yield 'division' => [['bin', '/', ['lit', 1], ['lit', 0]], \INF];
        yield 'remainder keeps sign' => [['bin', '%', ['lit', -30], ['lit', 360]], -30.0];
        yield 'not' => [['unary', '!', ['lit', '']], true];
        yield 'NaN comparison' => [['bin', '<', ['lit', 'abc'], ['lit', 1]], false];
        yield 'greater' => [['bin', '>', ['lit', 2], ['lit', '1']], true];
        yield 'at most' => [['bin', '<=', ['lit', 2], ['lit', 2]], true];
        yield 'at least' => [['bin', '>=', ['lit', 1], ['lit', 2]], false];
        yield 'string order' => [['bin', '<', ['lit', 'a'], ['lit', 'b']], true];
        yield 'numbers equal across types' => [['bin', '===', ['lit', 12], ['lit', 12.0]], true];
        yield 'strings differ' => [['bin', '!==', ['lit', 'a'], ['lit', 'a']], false];
        yield 'nullish null' => [['nullish', $this_('none'), ['lit', 'fallback']], 'fallback'];
        yield 'nullish zero' => [['nullish', ['lit', 0], ['lit', 'fallback']], 0];
        yield 'and' => [['and', ['lit', 1], ['lit', 'last']], 'last'];
        yield 'and short circuit' => [['and', ['lit', 0], ['call', 'nope', []]], 0];
        yield 'conditional' => [['cond', ['lit', ''], ['lit', 'yes'], ['lit', 'no']], 'no'];
        yield 'optional chain' => [['nullish', ['get', $this_('none'), ['lit', 'deep'], 1], ['lit', 'undefined']], 'undefined'];
        yield 'array index' => [['get', $this_('list'), ['lit', 2], 0], 2];
        yield 'fractional index' => [['nullish', ['get', $this_('list'), ['lit', 0.5], 0], ['lit', 'none']], 'none'];
        yield 'string property' => [['nullish', ['get', $this_('text'), ['lit', 'x'], 0], ['lit', 'none']], 'none'];
        yield 'utf-16 length' => [['length', $this_('text')], 3.0];
        yield 'array length' => [['length', $this_('list')], 3.0];
        yield 'alpha of a color' => [['call', 'rgba', [['lit', '#ff0000'], ['lit', 0.5]]], 'rgba(255, 0, 0, 0.5)'];
        yield 'numeric rgba defaults' => [['call', 'rgba', [['lit', 1], ['lit', 2]]], '#010200'];
        yield 'object literal skips undefined' => [['length', ['array', [['object', [['a', $this_('missing')]]]]]], 1.0];
    }

    public function testUndefinedValuesRemoveTheirPropertyAndLocalsAssign(): void
    {
        $self = (object) ['gone' => 'value'];
        new ThemeProgram([])->run([
            ['set', ['get', ['this'], ['lit', 'gone'], 0], ['get', ['this'], ['lit', 'missing'], 0]],
            ['let', 'total', ['lit', 1]],
            ['set', ['id', 'total'], ['bin', '+', ['id', 'total'], ['lit', 2]]],
            ['set', ['get', ['this'], ['lit', 'total'], 0], ['id', 'total']],
            ['if', ['lit', false], ['block', []], ['set', ['get', ['this'], ['lit', 'else'], 0], ['lit', true]]],
            ['if', ['lit', false], ['block', []], null],
            ['each', ['array', [['lit', 'a'], ['lit', 'b']]], 'item', 'index', ['set', ['get', ['this'], ['bin', '+', ['lit', 'item'], ['id', 'index']], 0], ['id', 'item']]],
            ['for', ['let', 'i', ['lit', 0]], ['bin', '<', ['id', 'i'], ['lit', '2']], 'i', ['set', ['get', ['this'], ['bin', '+', ['lit', 'loop'], ['id', 'i']], 0], ['id', 'i']]],
        ], $self);
        $this->assertSame(['total' => 3.0, 'else' => true, 'item0' => 'a', 'item1' => 'b', 'loop0' => 0, 'loop1' => 1.0], \get_object_vars($self));
    }

    /** @param list<mixed> $statement */
    #[DataProvider('failures')]
    public function testMalformedProgramsAndJavaScriptFailuresThrow(array $statement, string $exception): void
    {
        $this->expectException($exception);
        new ThemeProgram([])->run([$statement], (object) ['text' => 'x', 'none' => null]);
    }

    public static function failures(): iterable
    {
        yield 'statement' => [['while'], \UnexpectedValueException::class];
        yield 'expression' => [['set', ['id', 'x'], ['regex']], \UnexpectedValueException::class];
        yield 'operator' => [['set', ['id', 'x'], ['bin', '**', ['lit', 1], ['lit', 2]]], \UnexpectedValueException::class];
        yield 'function' => [['set', ['id', 'x'], ['call', 'mix', []]], \UnexpectedValueException::class];
        yield 'local' => [['set', ['id', 'x'], ['id', 'y']], \UnexpectedValueException::class];
        yield 'node' => [['set', ['id', 'x'], 'lit'], \UnexpectedValueException::class];
        yield 'name' => [['let', 3, ['lit', 1]], \UnexpectedValueException::class];
        yield 'primitive write' => [['set', ['get', ['lit', 'x'], ['lit', 'k'], 0], ['lit', 1]], \InvalidArgumentException::class];
        yield 'null read' => [['set', ['id', 'x'], ['get', ['get', ['this'], ['lit', 'none'], 0], ['lit', 'k'], 0]], \InvalidArgumentException::class];
        yield 'null length' => [['set', ['id', 'x'], ['length', ['lit', null]]], \InvalidArgumentException::class];
        yield 'forEach on a value' => [['each', ['lit', 1], 'a', 'b', ['block', []]], \InvalidArgumentException::class];
        yield 'adjust amounts' => [['set', ['id', 'x'], ['call', 'adjust', [['lit', '#fff'], ['lit', 1]]]], \InvalidArgumentException::class];
    }
}
