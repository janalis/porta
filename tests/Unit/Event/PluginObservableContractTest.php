<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Porta\Event\PluginObservable;

/**
 * The events a plugin may listen to are public API, so the promise needs a
 * gate rather than a convention.
 *
 * The payload rule is the one that does real work: it is what keeps
 * `Porta\Editor\Buffer` out of third-party hands. `final readonly` on an event
 * says nothing about what it points AT, and a `Buffer` is a mutable
 * public-property bag whose exposure would also hand plugins a route around
 * {@see \Porta\Editor\DocumentReplacer}'s undo/LSP contract.
 */
final class PluginObservableContractTest extends TestCase
{
    /** Adding to this list is the review point. */
    private const array OBSERVABLE = [
        'ActiveBufferChanged',
        'AgentActivity',
        'AgentEditing',
        'AgentLeft',
        'ChangeProposed',
        'CommandRunRequested',
        'DebugStatusChanged',
        'DiffToolLaunched',
        'FileDeleted',
        'FileRenamed',
        'FileRewritten',
        'GitCommitted',
        'GitRemoteCompleted',
        'IdeReady',
        'IdeShuttingDown',
        'LocaleChanged',
        'MergeToolLaunched',
        'QuitRequested',
        'ThemeChanged',
        'ToolsChanged',
        'WorkingTreeChanged',
    ];

    /** @return list<class-string> */
    private static function marked(): array
    {
        $marked = [];
        foreach (\glob(\dirname(__DIR__, levels: 3).'/src/Event/*.php') as $file) {
            $class = 'Porta\\Event\\'.\basename($file, suffix: '.php');
            if (\class_exists($class) && \is_a($class, PluginObservable::class, allow_string: true)) {
                $marked[] = $class;
            }
        }
        \sort($marked);

        return $marked;
    }

    public function testTheObservableSetIsExactlyWhatIsPinnedHere(): void
    {
        $this->assertSame(
            \array_map(static fn (string $name): string => 'Porta\\Event\\'.$name, self::OBSERVABLE),
            self::marked(),
            'marking an event observable freezes it as third-party API — say so here too',
        );
    }

    public function testEveryObservablePayloadIsAScalarOrABackedEnum(): void
    {
        foreach (self::marked() as $class) {
            $reflection = new \ReflectionClass($class);
            $this->assertTrue($reflection->isFinal(), $class.' must be final');
            $this->assertTrue($reflection->isReadOnly(), $class.' must be readonly');

            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                $name = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
                $ok = \in_array($name, ['string', 'int', 'float', 'bool'], strict: true) || \enum_exists($name) && null !== new \ReflectionEnum($name)->getBackingType();
                $this->assertTrue($ok, \sprintf('%s::$%s is a %s — an observable event carries scalars and backed enums, never core objects', $class, $parameter->getName(), $name));
            }
        }
    }

    public function testEveryObservableEventIsDocumented(): void
    {
        // The rule that would have caught the onThemeChanged gap: a hook that
        // shipped, was used by a bundled plugin, and appeared nowhere in the
        // plugin docs.
        $docs = (string) \file_get_contents(\dirname(__DIR__, levels: 3).'/docs/plugins.md');

        foreach (self::marked() as $class) {
            $this->assertStringContainsString(
                '`'.\basename(\str_replace('\\', replace: '/', subject: $class)).'`',
                $docs,
                $class.' is public API and must appear in docs/plugins.md',
            );
        }
    }
}
