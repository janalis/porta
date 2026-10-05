<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchSource;

/** A source that answers nothing by itself: it keeps each query's emit for the test to call, and counts cancels. */
final class RecordingSearchSource implements SearchSource
{
    /** @var list<\Closure> */
    public array $emits = [];
    public int $cancels = 0;

    public function __construct(
        private readonly SearchCategory $category,
    ) {}

    public function category(): SearchCategory
    {
        return $this->category;
    }

    public function query(string $query, \Closure $emit): void
    {
        $this->emits[] = $emit;
    }

    public function cancel(): void
    {
        ++$this->cancels;
    }
}
