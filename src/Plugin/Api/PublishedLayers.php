<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * What one plugin has put on screen through its API — findings, marks,
 * statuses, coverage, annotations, the change gutter — and where, so that
 * when it is switched off all of it can be taken back. Without this a
 * disabled git left its tree marks, gutter and blame frozen, and a disabled
 * analyser its findings, until each buffer was closed.
 */
final class PublishedLayers
{
    /** @var array<string, array{PublishedLayer, string}> one entry per layer and path ('' for a layer with none) */
    private array $published = [];

    public function record(PublishedLayer $layer, string $path = ''): void
    {
        $this->published[$layer->name."\0".$path] = [$layer, $path];
    }

    /**
     * Hand each layer and path published to $clear, once, and forget them.
     *
     * @param \Closure(PublishedLayer, string): void $clear
     */
    public function retract(\Closure $clear): void
    {
        $published = $this->published;
        $this->published = [];
        foreach ($published as [$layer, $path]) {
            $clear($layer, $path);
        }
    }
}
