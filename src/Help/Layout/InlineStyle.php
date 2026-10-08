<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Ui\Role;

/**
 * How a run of page text is drawn: any mix of bold, italic, struck, code,
 * link, key and muted, over an optional base colour (a heading's).
 */
final readonly class InlineStyle
{
    public const int BOLD = 1;
    public const int ITALIC = 2;
    public const int STRIKE = 4;
    public const int CODE = 8;
    public const int LINK = 16;
    public const int KBD = 32;
    public const int MUTED = 64;

    public function __construct(
        public int $flags = 0,
        public ?Role $role = null,
    ) {}

    public function with(int $flag): self
    {
        return new self($this->flags | $flag, $this->role);
    }

    public function has(int $flag): bool
    {
        return 0 !== ($this->flags & $flag);
    }

    public function plain(): bool
    {
        return 0 === $this->flags && null === $this->role;
    }
}
