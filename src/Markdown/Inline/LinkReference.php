<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/** A link reference definition (`[label]: /url "title"`), what a reference link resolves to. */
final readonly class LinkReference
{
    public function __construct(
        public string $destination,
        public string $title,
    ) {}
}
