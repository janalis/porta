<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Help\Fence\FenceResult;
use Porta\Help\Fence\FenceSession;
use Porta\Highlight\CodeHighlighter;
use Porta\Markdown\Node\CodeBlock;
use Porta\Support\AnsiText;
use Porta\Support\LanguageMap;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A code block as a shaded box the page's full width: a row above with the
 * language's name at its right, the code coloured by its language's grammar,
 * a row below. Code never wraps — reflowing it changes what it says — so a
 * line wider than the box is cut. The colouring of each block is remembered
 * until its text or language changes, because a resize lays the page out
 * again but changes no code.
 */
final class CodeLayout
{
    private const int MEMO = 256;
    private const int INSET = 2;

    /** @var array<string, list<string>> */
    private array $memo = [];
    private int $version = 0;

    public function __construct(
        private readonly StylePen $pen,
        private readonly ?CodeHighlighter $highlighter = null,
        private readonly ?FenceSession $fences = null,
        private readonly ?ImageLayout $images = null,
        private readonly ?PageWords $words = null,
    ) {}

    /** Theme colours are baked into the memo: drop it on a theme switch. */
    public function refreshTheme(): void
    {
        $this->memo = [];
        $this->highlighter?->refreshTheme();
    }

    /** @return list<PageLine> */
    public function lines(CodeBlock $code, int $width, LayoutState $state): array
    {
        $raw = '' === $code->literal ? [] : \explode("\n", \substr($code->literal, offset: 0, length: -1));
        $language = \preg_split('/\s+/', $code->info)[0] ?? '';
        $first = $state->lineOf($code->fenced ? $code->body->start : $code->range->start);
        $block = $this->fences?->block($code);
        $label = null !== $block && $block->pending ? $language.' · '.($this->words ?? PageWords::english())->diagramPending() : $language;
        $lines = [$this->edge($width, $label, $state->lineOf($code->range->start))];
        $generated = $this->diagram($code, $width, $state);
        if (null !== $generated) {
            $lines = [...$lines, ...$generated];
        }
        foreach (null === $generated ? $this->highlight(\array_map(self::expandTabs(...), $raw), $language) : [] as $index => $painted) {
            $lines[] = $this->codeLine($painted, $raw[$index], $width, $first + $index, $state);
        }
        $error = $block?->error;
        if (null === $generated && null !== $block?->result && null === $error) {
            $error = ($this->words ?? PageWords::english())->diagramImageError();
        }
        if (null !== $error) {
            $lines[] = new PageLine($this->pen->role(AnsiText::truncate($error, $width), Role::StatusError), $width, $first);
        }
        $lines[] = $this->edge($width, '', $first + \max(0, \count($raw) - 1));

        return $lines;
    }

    /** @return list<PageLine>|null */
    private function diagram(CodeBlock $code, int $width, LayoutState $state): ?array
    {
        $block = $this->fences?->block($code);
        if (null === $block?->result) {
            return null;
        }
        $result = $block->result;
        $room = \max(1, $width - (2 * self::INSET));
        $first = $state->lineOf($code->body->start);

        $rows = null !== $result->png
            ? $this->imageRows($result->png, $room, $first, $code)
            : $this->nativeRows($result, $room, $first, $code);
        if (null !== $rows) {
            $links = new FenceLinks(
                $block->interactive() ? $result : null,
                $code->body->start,
                $first,
                \substr_count(\rtrim($code->literal, characters: "\n"), needle: "\n"),
                $room,
                $this->pen,
                $state,
            );
            $rows = null !== $result->png ? $links->image($rows) : $links->terminal($rows);
            $rows = [...$rows, ...$links->legend(), ...$links->controls()];
        }

        return $rows;
    }

    /** @return list<PageLine>|null */
    private function imageRows(string $png, int $room, int $first, CodeBlock $code): ?array
    {
        $rows = $this->images?->png($png, $room);

        return null === $rows
            ? null
            : \array_map(
                static fn (PageLine $row): PageLine => new PageLine($row->text, $row->width, $first, cells: [[
                    0,
                    $row->width,
                    $code->body->start,
                    $code->body->start,
                    '',
                ]])->after(PageLine::spaces(self::INSET)),
                $rows,
            );
    }

    /** @return list<PageLine> */
    private function nativeRows(FenceResult $result, int $room, int $first, CodeBlock $code): array
    {
        $rows = [];
        $last = \max(0, \substr_count(\rtrim($code->literal, characters: "\n"), needle: "\n"));
        foreach ($result->rows as $index => $text) {
            $origin = $first + \min($last, $result->origins[$index]);
            $painted = $this->pen->styles->hasColor() ? $text : AnsiUtils::stripAnsiCodes($text);
            $rows[] = new PageLine(AnsiText::truncate($painted, $room), $room, $origin)->after(PageLine::spaces(self::INSET));
        }

        return $rows;
    }

    /** The shaded row above (with the language's name) or below the code. */
    private function edge(int $width, string $label, int $origin): PageLine
    {
        $label = AnsiText::truncate($label, \max(0, $width - 2));
        $labelWidth = AnsiUtils::visibleWidth($label);
        $pad = \str_repeat(' ', \max(0, $width - $labelWidth - ('' === $label ? 0 : 1)));
        if (!$this->pen->styles->hasColor()) {
            return new PageLine($pad.$label.('' === $label ? '' : ' '), $width, $origin);
        }
        $label = '' === $label ? '' : $this->pen->styles->of(Role::Muted)->getAnsiRestore().$label.Sgr::DEFAULT_FG.' ';

        return new PageLine($this->background().$pad.$label.Sgr::RESET, $width, $origin);
    }

    private function codeLine(string $painted, string $raw, int $width, int $origin, LayoutState $state): PageLine
    {
        $room = \max(1, $width - (2 * self::INSET));
        $text = AnsiText::truncate($painted, $room);
        $pad = \str_repeat(' ', \max(0, $width - self::INSET - AnsiUtils::visibleWidth($text)));
        $inset = \str_repeat(' ', self::INSET);
        $drawn = $this->pen->styles->hasColor() ? $this->background().$inset.$text.$this->background().$pad.Sgr::RESET : $inset.$text.$pad;

        return new PageLine($drawn, $width, $origin, [], $state->lineEndCells($origin, $raw, self::INSET));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function highlight(array $lines, string $language): array
    {
        $grammar = LanguageMap::highlightForFence($language);
        if (null === $this->highlighter || null === $grammar || [] === $lines || !$this->pen->styles->hasColor()) {
            return $lines;
        }
        $key = $grammar."\0".\implode("\n", $lines);
        if (!\array_key_exists($key, $this->memo)) {
            if (\count($this->memo) >= self::MEMO) {
                $this->memo = [];
            }
            $this->highlighter->setLanguage('markdown-fence', $grammar);
            $this->memo[$key] = \array_values($this->highlighter->highlight($lines, ++$this->version));
        }

        return $this->memo[$key];
    }

    private function background(): string
    {
        return $this->pen->styles->of(Role::Toolbar)->getAnsiRestore();
    }

    private static function expandTabs(string $line): string
    {
        $expanded = '';
        foreach (\explode("\t", $line) as $index => $piece) {
            $expanded .= (0 === $index ? '' : \str_repeat(' ', \max(0, 4 - (AnsiUtils::visibleWidth($expanded) % 4)))).$piece;
        }

        return $expanded;
    }
}
