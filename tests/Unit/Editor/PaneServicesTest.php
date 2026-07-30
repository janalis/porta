<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\PaneServices;

/**
 * The DTO every plugin pane is constructed with. Its docblock says growing it
 * "should stay hard"; this is what makes that true — a comment asking for
 * review is not a gate, and the whole point of the DTO is that the pressure to
 * hand panes more of core becomes visible instead of accumulating one
 * convenient dependency at a time.
 */
final class PaneServicesTest extends TestCase
{
    public function testItHandsAPaneExactlyFourThings(): void
    {
        $members = array_map(
            static fn (\ReflectionParameter $param): string => $param->getName().': '.(string) $param->getType(),
            new \ReflectionClass(PaneServices::class)->getConstructor()?->getParameters() ?? [],
        );

        // Adding a member is a deliberate widening of the plugin pane contract:
        // update this list in the same commit and say in the message what a
        // pane could not do without it.
        $this->assertSame(
            [
                'styles: Porta\Ui\Theme\Styles',
                'translator: Porta\I18n\Translator',
                'scheduler: Porta\Support\Scheduler',
                'tabs: Porta\Widget\ViewerTabHandler',
            ],
            $members,
        );
    }

    public function testEveryMemberIsReadableByThePaneItself(): void
    {
        // They are promoted PUBLIC properties on a readonly class, not getters:
        // a pane reads `$services->styles` in its render path, and the readonly
        // is what keeps core's holder from being swapped underneath it.
        $class = new \ReflectionClass(PaneServices::class);

        $this->assertTrue($class->isReadOnly());
        $this->assertTrue($class->isFinal());
        foreach ($class->getProperties() as $property) {
            $this->assertTrue($property->isPublic(), $property->getName().' is readable by the pane');
        }
    }
}
