<?php

declare(strict_types=1);

namespace Porta\Help\Fence;

use Porta\Ui\Graphics\PngDimensions;

/** A completed Markdown fence render; row origins are zero-based source lines. */
final readonly class FenceResult
{
    /**
     * @param list<string> $rows
     * @param list<int> $origins
     * @param list<FenceElement> $elements
     * @param list<FenceAction> $actions
     */
    private function __construct(
        public ?string $png,
        public array $rows,
        public array $origins,
        public ?string $error,
        public array $elements = [],
        public array $actions = [],
    ) {
        if (\count($elements) > 4096 || \count($actions) > 16) {
            throw new \InvalidArgumentException('Fence interaction limit exceeded');
        }
        $seen = [];
        foreach ([...$elements, ...$actions] as $item) {
            $key = $item::class.':'.$item->id;
            if (\array_key_exists($key, $seen)) {
                throw new \InvalidArgumentException('Fence interaction IDs must be unique');
            }
            $seen[$key] = true;
        }
    }

    /** @param list<FenceElement> $elements
     * @param list<FenceAction> $actions
     */
    public function interactive(array $elements, array $actions = []): self
    {
        return new self($this->png, $this->rows, $this->origins, $this->error, $elements, $actions);
    }

    public static function png(string $bytes, ?string $error = null): self
    {
        if (null === PngDimensions::read($bytes)) {
            throw new \InvalidArgumentException('Fence images must contain PNG bytes');
        }

        return new self($bytes, rows: [], origins: [], error: $error);
    }

    /**
     * @param list<string> $rows
     * @param list<int> $origins
     */
    public static function rows(array $rows, array $origins, ?string $error = null): self
    {
        if (\count($rows) !== \count($origins)) {
            throw new \InvalidArgumentException('Every fence row needs a source origin');
        }
        foreach ($origins as $origin) {
            if ($origin < 0) {
                throw new \InvalidArgumentException('Fence source origins cannot be negative');
            }
        }

        return new self(png: null, rows: $rows, origins: $origins, error: $error);
    }
}
