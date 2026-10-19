<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Domain replacement and self-loop filtering follow the pinned upstream database. */
final class CynefinParser
{
    private readonly CynefinDiagram $diagram;
    private bool $header = false;
    private ?string $domain = null;
    private int $blocks = 0;
    private int $items = 0;
    private int $transitions = 0;

    public function __construct()
    {
        $this->diagram = new CynefinDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (!$this->header) {
            if (1 !== \preg_match('/^cynefin-beta:?$/i', $statement->text)) {
                throw $statement->error('Expected cynefin-beta');
            }
            $this->header = true;
            $this->diagram->origin = $statement->line;

            return;
        }
        $this->declaration($statement);
    }

    private function declaration(MermaidStatement $statement): void
    {
        $match = [];
        if (1 === \preg_match('/^(title(?:[\t ]|$)|accTitle\s*:|accDescr\s*[:{])(.*)$/is', $statement->text, $match)) {
            $this->domain = null;
            $name = \strtolower(\rtrim($match[1], characters: " \t:{"));
            $body = $match[2];
            if (\str_ends_with($match[1], '{')) {
                $body = \substr($body, offset: 0, length: -1);
            }
            $body = \preg_replace('/%%[^\r\n]*/', replacement: '', subject: $body) ?? $body;
            $this->diagram->metadata[$name] = ['label' => MermaidText::decode(\trim($body)), 'origin' => $statement->line];

            return;
        }
        if (\in_array($statement->text, CynefinDiagram::DOMAINS, strict: true)) {
            if (++$this->blocks > MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the Cynefin domain-block limit');
            }
            $this->domain = $statement->text;
            $this->diagram->domains[$this->domain] = ['origin' => $statement->line, 'items' => []];

            return;
        }
        $this->content($statement);
    }

    private function content(MermaidStatement $statement): void
    {
        $match = [];
        if (1 === \preg_match('~^(\w+)\s*-->\s*(\w+)(?:\s*:\s*('.CynefinLexer::STRING.'))?$~s', $statement->text, $match)) {
            $this->transition($match[1], $match[2], $match[3] ?? '', $statement);

            return;
        }
        if (null === $this->domain || 1 !== \preg_match('~^(?:'.CynefinLexer::STRING.')$~s', $statement->text)) {
            throw $statement->error('Expected a quoted item inside a Cynefin domain');
        }
        if (++$this->items > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the Cynefin item limit');
        }
        $this->diagram->domains[$this->domain]['items'][] = ['label' => self::label($statement->text), 'origin' => $statement->line];
    }

    private function transition(string $from, string $to, string $label, MermaidStatement $statement): void
    {
        $this->domain = null;
        if (!\in_array($from, CynefinDiagram::DOMAINS, strict: true) || !\in_array($to, CynefinDiagram::DOMAINS, strict: true)) {
            throw $statement->error('Unknown Cynefin transition domain');
        }
        if (++$this->transitions > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Diagram exceeds the Cynefin transition limit');
        }
        if ($from !== $to) {
            $this->diagram->transitions[] = ['from' => $from, 'to' => $to, 'label' => '' === $label ? '' : self::label($label), 'origin' => $statement->line];
        }
    }

    private static function label(string $text): string
    {
        $body = \substr($text, offset: 1, length: -1);

        return MermaidText::decode(\strtr($body, ['\\"' => '"', "\\'" => "'", '\\\\' => '\\']));
    }

    public function finish(): CynefinDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected cynefin-beta', offset: 0, sourceLine: 0, column: 0);
        }

        return $this->diagram;
    }
}
