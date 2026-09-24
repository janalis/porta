<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\SignatureHint;

final class SignatureHintTest extends TestCase
{
    public function testOverloadsCycleBothWaysAndCount(): void
    {
        $hint = new SignatureHint('f(a)', [2, 3], [['f(a)', [2, 3]], ['f(a, b)', [5, 6]], ['f()', null]]);

        $this->assertSame('1/3', $hint->counter());
        $this->assertSame(['f(a, b)', [5, 6], '2/3'], [$hint->cycled(1)->label, $hint->cycled(1)->activeRange, $hint->cycled(1)->counter()]);
        $this->assertSame('f()', $hint->cycled(-1)->label, 'wrapping backwards');
        $this->assertSame('f(a)', $hint->cycled(3)->label, 'and forwards');
    }

    /** One signature is no choice: no counter, and cycling is the hint itself. */
    public function testASingleSignatureDoesNotCycle(): void
    {
        $alone = new SignatureHint('f()', null, [['f()', null]]);

        $this->assertFalse($alone->hasOverloads());
        $this->assertSame('', $alone->counter());
        $this->assertSame($alone, $alone->cycled(1));
        $this->assertSame($bare = new SignatureHint('g()'), $bare->cycled(-1), 'nor with none at all');
    }
}
