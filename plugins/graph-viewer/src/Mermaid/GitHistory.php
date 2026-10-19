<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** History operations validate references before changing a branch tip. */
final readonly class GitHistory
{
    public function __construct(
        private GitDiagram $diagram,
    ) {}

    /** @param array<string, list<string>> $options */
    public function accept(string $command, array $options, MermaidStatement $statement): void
    {
        if (!\array_key_exists('branch', $options) && \in_array($command, ['branch', 'checkout', 'switch', 'merge'], strict: true)) {
            throw $statement->error('Git command requires a branch name');
        }
        $branch = $options['branch'][0] ?? '';
        match ($command) {
            'branch' => $this->branch($branch, (int) ($options['order'][0] ?? '0'), $statement),
            'checkout', 'switch' => $this->checkout($branch, $statement),
            'merge' => $this->merge($branch, $options, $statement),
            'cherry-pick' => $this->cherry($options, $statement),
            default => $this->commit($options, $statement),
        };
    }

    private function branch(string $name, int $order, MermaidStatement $statement): void
    {
        if (\array_key_exists($name, $this->diagram->branches)) {
            throw $statement->error('Git branch already exists');
        }
        $this->diagram->branches[$name] = ['tip' => $this->tip(), 'order' => $order, 'origin' => $statement->line];
        $this->diagram->current = $name;
    }

    private function checkout(string $name, MermaidStatement $statement): void
    {
        if (!\array_key_exists($name, $this->diagram->branches)) {
            throw $statement->error('Git branch does not exist');
        }
        $this->diagram->current = $name;
    }

    /** @param array<string, list<string>> $options */
    private function commit(array $options, MermaidStatement $statement): void
    {
        $tip = $this->tip();
        $this->append($options, $options['type'][0] ?? 'NORMAL', $options['msg'][0] ?? '', null === $tip ? [] : [$tip], $statement);
    }

    /** @param array<string, list<string>> $options */
    private function merge(string $branch, array $options, MermaidStatement $statement): void
    {
        $tip = $this->tip();
        $other = $this->diagram->branches[$branch]['tip'] ?? null;
        if (null === $tip || null === $other || $tip === $other || $branch === $this->diagram->current || $this->diagram->commits[$tip]['branch'] === $branch) {
            throw $statement->error('Git merge requires distinct committed branch heads');
        }
        $id = $options['id'][0] ?? '';
        if ('' !== $id && \array_key_exists($id, $this->diagram->commits)) {
            throw $statement->error('Git merge commit id already exists');
        }
        $this->append($options, 'MERGE', 'merged branch '.$branch.' into '.$this->diagram->current, [$tip, $other], $statement);
    }

    /** @param array<string, list<string>> $options */
    private function cherry(array $options, MermaidStatement $statement): void
    {
        $id = $options['id'][0] ?? '';
        $source = $this->diagram->commits[$id] ?? null;
        $tip = $this->tip();
        $parent = $options['parent'][0] ?? null;
        if (null === $source || null === $tip || $source['branch'] === $this->diagram->current) {
            throw $statement->error('Git cherry-pick requires a source on another branch and a committed current branch');
        }
        if ((null !== $parent && !\in_array($parent, $source['parents'], strict: true)) || ('MERGE' === $source['type'] && null === $parent)) {
            throw $statement->error('Git cherry-pick requires an immediate parent for merge commits');
        }
        $tags = $options['tag'] ?? ['cherry-pick:'.$id.('MERGE' === $source['type'] ? '|parent:'.($parent ?? '') : '')];
        $this->append(
            ['tag' => \array_values(\array_filter($tags, static fn (string $tag): bool => '' !== $tag))],
            'CHERRY_PICK',
            'cherry-picked '.$source['message'].' into '.$this->diagram->current,
            [$tip, $id],
            $statement,
        );
    }

    private function tip(): ?string
    {
        return $this->diagram->branches[$this->diagram->current]['tip'];
    }

    /** @param array<string, list<string>> $options
     * @param list<string> $parents
     */
    private function append(array $options, string $type, string $message, array $parents, MermaidStatement $statement): void
    {
        $seq = $this->diagram->sequence++;
        $id = $options['id'][0] ?? '';
        if ('' === $id) {
            $id = $seq.'-native';
            while (\array_key_exists($id, $this->diagram->commits)) {
                $id .= '-';
            }
        }
        $this->diagram->commits[$id] = [
            'id' => $id,
            'custom' => '' !== ($options['id'][0] ?? ''),
            'message' => $message,
            'tags' => $options['tag'] ?? [],
            'type' => $type,
            'customType' => 'MERGE' === $type ? $options['type'][0] ?? null : null,
            'branch' => $this->diagram->current,
            'parents' => $parents,
            'seq' => $seq,
            'origin' => $statement->line,
        ];
        $this->diagram->branches[$this->diagram->current]['tip'] = $id;
    }
}
