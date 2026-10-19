<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Nullable options preserve explicit false and zero through document-local overlays. */
final readonly class GitConfiguration
{
    /** The options this family's native rendering reads here; MermaidConfiguration leaves them to this parser. */
    public const array KEYS = ['mainBranchName', 'mainBranchOrder', 'showBranches', 'showCommitLabel', 'parallelCommits', 'rotateCommitLabel'];

    /** @param array<array-key, bool|int|string> $options */
    public function __construct(
        private array $options = [],
    ) {}

    public static function parse(\stdClass $object, MermaidStatement $statement): self
    {
        $options = [];
        // @mago-expect analysis:mixed-assignment -- Each closed option is narrowed before storage below.
        foreach (\get_object_vars($object) as $key => $value) {
            if (!\in_array($key, self::KEYS, strict: true)) {
                continue; // Other pinned options are validated by MermaidOptionSchema.
            }
            $valid = match ($key) {
                'mainBranchName' => \is_string($value) && '' !== $value && \strlen($value) <= 4096,
                'mainBranchOrder' => \is_int($value) && $value >= 0 && $value <= 1_000_000,
                default => \is_bool($value), // showBranches, showCommitLabel, parallelCommits, rotateCommitLabel
            };
            if (!$valid || (!\is_bool($value) && !\is_int($value) && !\is_string($value))) {
                throw $statement->error('Unsupported Git graph configuration value ('.$key.')');
            }
            $options[$key] = $value;
        }

        return new self($options);
    }

    public function overlay(self $later): self
    {
        return new self($later->options + $this->options);
    }

    public function configured(): bool
    {
        return [] !== $this->options;
    }

    public function name(): string
    {
        $value = $this->options['mainBranchName'] ?? 'main';

        return \is_string($value) ? $value : 'main';
    }

    public function order(): int
    {
        $value = $this->options['mainBranchOrder'] ?? 0;

        return \is_int($value) ? $value : 0;
    }

    public function branches(): bool
    {
        return false !== ($this->options['showBranches'] ?? true);
    }

    public function labels(): bool
    {
        return false !== ($this->options['showCommitLabel'] ?? true);
    }

    /** Pinned `rotateCommitLabel` defaults on. */
    public function rotated(): bool
    {
        return false !== ($this->options['rotateCommitLabel'] ?? true);
    }

    public function parallel(): bool
    {
        return true === ($this->options['parallelCommits'] ?? false);
    }
}
