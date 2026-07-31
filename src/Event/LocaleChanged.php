<?php

declare(strict_types=1);

namespace Porta\Event;

/** The UI locale was switched live (translator already re-pointed). */
final readonly class LocaleChanged implements PluginObservable
{
    public function __construct(
        public string $locale,
    ) {}
}
