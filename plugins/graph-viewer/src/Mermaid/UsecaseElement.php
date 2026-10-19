<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** References may be refined by a later declaration; explicit declarations retain their invariants. */
final class UsecaseElement
{
    public string $kind = 'usecase';
    public string $shape = 'ellipse';
    public string $raw;
    public string $label;
    public bool $markdown = false;
    public bool $declared = false;
    public bool $generated = false;
    public bool $shaped = false;
    public ?string $parent = null;
    public ?string $stereotype = null;
    /** @var array<string, string|bool> */
    public array $metadata = [];
    /** @var list<string> */
    public array $classes = [];
    public ?\stdClass $json = null;

    public function __construct(
        public readonly string $id,
        public MermaidStatement $statement,
    ) {
        $this->raw = $id;
        $this->label = $id;
    }
}
