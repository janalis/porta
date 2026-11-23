<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Help\Layout\Fragment;
use Porta\Help\Layout\InlineFragments;
use Porta\Help\Layout\LayoutState;
use Porta\Markdown\Node\Inline;
use Porta\Markdown\Node\Text;
use Porta\Markdown\SourceRange;
use Porta\Markdown\SourceText;

final class InlineFragmentsTest extends TestCase
{
    /** A kind of inline the parser gains before this layout learns it is left out, not a broken Help page. */
    public function testAnInlineItDoesNotKnowIsLeftOut(): void
    {
        $unknown = new class(new SourceRange(2, 3)) implements Inline {
            public function __construct(
                public SourceRange $range,
            ) {}

            #[\Override]
            public function children(): array
            {
                return [];
            }
        };

        $fragments = new InlineFragments(new LayoutState(new SourceText('a ∑ b')))->of([new Text('a', new SourceRange(0, 1)), $unknown, new Text('b', new SourceRange(4, 5))]);

        $this->assertSame(['a', 'b'], \array_map(static fn (Fragment $f): string => $f->text, $fragments));
    }
}
