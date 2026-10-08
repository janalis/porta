<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

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
        $lines = [$this->edge($width, $language, $state->lineOf($code->range->start))];
        foreach ($this->highlight(\array_map(self::expandTabs(...), $raw), $language) as $index => $painted) {
            $lines[] = $this->codeLine($painted, $raw[$index], $width, $first + $index, $state);
        }
        $lines[] = $this->edge($width, '', $first + \max(0, \count($raw) - 1));

        return $lines;
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
