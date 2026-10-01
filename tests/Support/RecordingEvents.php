<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

/** A dispatcher that only keeps what was dispatched, in order. */
final class RecordingEvents implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }

    /**
     * Every event of $class, and forget them all.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function take(string $class): array
    {
        $taken = [];
        foreach ($this->dispatched as $event) {
            if (!$event instanceof $class) {
                continue;
            }
            $taken[] = $event;
        }
        $this->dispatched = [];

        return $taken;
    }
}
