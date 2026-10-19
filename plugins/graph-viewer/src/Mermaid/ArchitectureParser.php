<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Architecture keeps named ports and alignment constraints instead of translating them into flowchart arrows. */
final class ArchitectureParser
{
    private readonly ArchitectureDiagram $diagram;
    private bool $header = false;
    private int $count = 0;

    public function __construct()
    {
        $this->diagram = new ArchitectureDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (++$this->count > 16_384) {
            throw $statement->error('Architecture declaration limit exceeded');
        }
        if (!$this->header) {
            if ('architecture-beta' !== $statement->text) {
                throw $statement->error('Expected architecture-beta header');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        $text = $statement->text;
        match (true) {
            1 === \preg_match('/^(?:group|service|junction)\s/', $text) => $this->item($statement),
            1 === \preg_match('/^align\s/', $text) => $this->alignment($statement),
            1 === \preg_match('/^(?:title|accTitle|accDescr)\b/', $text) => $this->metadata($statement),
            default => $this->edge($statement),
        };
    }

    private function item(MermaidStatement $statement): void
    {
        $match = [];
        $pattern =
            '/^(group|service|junction)\s+('
            .ArchitectureText::ID
            .')(?:\s*('
            .ArchitectureText::ICON
            .'|'
            .MermaidQuotedString::PATTERN
            .'))?(?:\s*('
            .ArchitectureText::TITLE
            .'))?(?:\s+in\s+('
            .ArchitectureText::ID
            .'))?$/';
        if (1 !== \preg_match($pattern, $statement->text, $match)) {
            throw $statement->error('Invalid architecture declaration');
        }
        $kind = $match[1];
        $id = ArchitectureText::identifier($match[2], $statement);
        $symbol = $match[3] ?? '';
        $title = $match[4] ?? '';
        $parent = $match[5] ?? '';
        $icon = \str_starts_with($symbol, '(') ? \substr($symbol, offset: 1, length: -1) : null;
        $iconText = '' !== $symbol && null === $icon ? MermaidQuotedString::decode($symbol) : null;
        if (('junction' === $kind && ('' !== $symbol || '' !== $title)) || ('group' === $kind && null !== $iconText)) {
            throw $statement->error('Invalid architecture symbol or title for '.$kind);
        }
        $this->diagram->add(
            $id,
            $kind,
            $icon,
            $iconText,
            '' === $title ? null : ArchitectureText::title($title),
            '' === $parent ? null : ArchitectureText::identifier($parent, $statement),
            $statement,
        );
    }

    private function edge(MermaidStatement $statement): void
    {
        $match = [];
        $pattern =
            '/^('.ArchitectureText::ID.')(\{group\})?\s*:\s*([LRTB])\s*([<>])?\s*(?:--|-\s*('.ArchitectureText::TITLE.')\s*-)\s*([<>])?\s*([LRTB])\s*:\s*('.ArchitectureText::ID.')(\{group\})?$/s';
        if (1 !== \preg_match($pattern, $statement->text, $match)) {
            throw $statement->error('Expected an architecture edge with explicit L, R, T or B ports');
        }
        $this->diagram->edge([
            'lhsId' => ArchitectureText::identifier($match[1], $statement),
            'lhsDir' => $match[3],
            'lhsInto' => '' !== ($match[4] ?? ''),
            'lhsGroup' => '' !== $match[2],
            'rhsId' => ArchitectureText::identifier($match[8], $statement),
            'rhsDir' => $match[7],
            'rhsInto' => '' !== $match[6],
            'rhsGroup' => '' !== ($match[9] ?? ''),
            'title' => '' === $match[5] ? null : ArchitectureText::title($match[5]),
            'statement' => $statement,
        ]);
    }

    private function alignment(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^align\s+(row|column)\s+('.ArchitectureText::ID.'(?:\s+'.ArchitectureText::ID.')+)$/', $statement->text, $match)) {
            throw $statement->error('Expected align row or column followed by at least two members');
        }
        $parts = \preg_split('/\s+/', $match[2]);
        $members = false === $parts ? [] : $parts;
        foreach ($members as $id) {
            ArchitectureText::identifier($id, $statement);
        }
        $this->diagram->align($match[1], $members, $statement);
    }

    private function metadata(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('/^(title)(?:\s+(.*))?$|^(accTitle|accDescr)\s*:\s*(.*)$|^(accDescr)\s*\{([^}]*)\}$/s', $statement->text, $match)) {
            throw $statement->error('Invalid architecture title or accessibility metadata');
        }
        $key = $match[5] ?? $match[3] ?? $match[1];
        $text = MermaidMetadata::normalize($match[6] ?? $match[4] ?? $match[2] ?? '');
        $this->diagram->metadata[$key] = $text;
        $this->diagram->legend[] = ['text' => $text, 'origin' => $statement->line];
    }

    public function finish(): ArchitectureDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected architecture-beta header', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
