<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Services;

/**
 * An Ide-scope action's default key must actually DISPATCH.
 *
 * {@see ActionCatalogTest::testEveryActionIsDefaultBoundInExactlyOnePlace}
 * already asks whether a catalog entry is default-BOUND. That is a different
 * question from whether the binding is reachable: a command registered without
 * `keyBound: KeyDispatch::Bound` is absent from
 * {@see CommandRegistry::keyBoundIds()}, which is the only list
 * {@see \Porta\Input\InputDispatcher} walks. The chord is then displayed on the
 * Settings ▸ Keymap page and is rebindable there, and neither the default nor
 * the rebinding does anything.
 *
 * That is not hypothetical: `open_as_text` (Ctrl+Alt+X) and `ide_notifications`
 * (Alt+9) both shipped that way, and every test drove them through
 * `CommandRegistry::run()`, which is the one path that cannot notice.
 */
final class KeyDispatchReachabilityTest extends TestCase
{
    use BootsIde;

    public function testEveryIdeScopeDefaultKeyReachesTheInputDispatcher(): void
    {
        $ide = $this->bootIde(['composer.json' => '{}']);
        $dispatchable = \array_flip(Services::resolve($ide, CommandRegistry::class)->keyBoundIds());

        $dead = [];
        foreach (ActionCatalog::ACTIONS as $id => $spec) {
            if (!\array_key_exists('keys', $spec) || ActionCatalog::isAdoptable($id)) {
                // Keyless entries are widget-scope (the test above covers them),
                // and an adoptable id belongs to a plugin that this bare fixture
                // project does not load.
                continue;
            }
            if (\str_starts_with($id, 'graph_') || \str_starts_with($id, 'image_')) {
                continue; // a viewer plugin's pane binds these; its own suite pins them
            }
            if (!\array_key_exists($id, $dispatchable)) {
                $dead[$id] = \implode(', ', $spec['keys']);
            }
        }

        $this->assertSame([], $dead, 'these actions advertise a key the input dispatcher never sees');
    }
}
