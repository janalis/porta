<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorDecorationSink;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * PhpStorm's semantic colouring, via `textDocument/semanticTokens/full`.
 *
 * The lexer colours by SHAPE — `$x` is a variable, `Foo` starts with a capital
 * so it is probably a type. The server colours by MEANING: it knows that this
 * identifier is a property and that one a local, that this method is `static`
 * and that symbol `deprecated`. That is the whole difference this makes.
 *
 * **Gated on the capability**, like every other optional request here:
 * phpantom advertises `semanticTokensProvider`, phpactor does not, and the
 * ungated path is how a previous round shipped a feature that flashed a
 * misleading message on a server that never offered it. With no capability the
 * editor keeps exactly the lexer's colours.
 *
 * The legend is read from the capability rather than hardcoded: the token type
 * and modifier INDICES are per server, so phpantom's order baked in would paint
 * the wrong colours on the next server that answers.
 */
#[AsIdeService]
final readonly class SemanticTokensController
{
    public function __construct(
        private LspSessionPool $lsp,
        private CodeEditorWidget $editor,
        private EditorDecorationSink $decorations,
        private Tui $tui,
    ) {}

    public function requestSemanticTokens(): void
    {
        $path = $this->editor->getFilePath();
        /** @var array<string, mixed>|bool|null $capability LSP: a SemanticTokensOptions object */
        $capability = $this->lsp->serverCapability($path, 'semanticTokensProvider');
        if (null === $path || !\is_array($capability)) {
            return;
        }

        /** @var array<string, mixed> $legend */
        $legend = \is_array($capability['legend'] ?? null) ? $capability['legend'] : [];
        $types = self::stringList($legend['tokenTypes'] ?? null);
        // `full` may be a boolean or a `{delta: bool}` object; either way an
        // absent or false `full` means the server only answers ranges, which
        // this request does not ask for.
        $answersWholeDocument = false !== ($capability['full'] ?? false);
        if ([] === $types || !$answersWholeDocument) {
            return;
        }

        $modifiers = self::stringList($legend['tokenModifiers'] ?? null);
        $this->lsp->request(
            'textDocument/semanticTokens/full',
            ['textDocument' => ['uri' => Uri::fromPath($path)]],
            function (mixed $result) use ($path, $types, $modifiers): void {
                // A stale answer after a buffer switch describes a file that is
                // no longer on screen; painting it would colour the wrong code.
                if (!\is_array($result) || $path !== $this->editor->getFilePath()) {
                    return;
                }

                $this->decorations->setSemanticTokens(self::decode(self::intList($result['data'] ?? null), $types, $modifiers));
                $this->tui->requestRender();
            },
        );
    }

    /**
     * Expand LSP's flat, delta-encoded token stream into tokens grouped by line.
     *
     * The wire format is one flat array of 5-tuples — `deltaLine`,
     * `deltaStartChar`, `length`, `typeIndex`, `modifierBits` — where each tuple
     * is relative to the PREVIOUS token, and `deltaStartChar` restarts from the
     * line start whenever `deltaLine` is non-zero. Columns are UTF-16.
     *
     * @param list<int>    $data
     * @param list<string> $types     the server's tokenTypes legend
     * @param list<string> $modifiers the server's tokenModifiers legend
     *
     * @return array<int, list<array{col: int, endCol: int, type: string, modifiers: list<string>}>>
     */
    public static function decode(array $data, array $types, array $modifiers): array
    {
        $byLine = [];
        $line = 0;
        $col = 0;
        // A trailing partial tuple is malformed; stop rather than read past it.
        $count = \intdiv(\count($data), num2: 5) * 5;
        for ($i = 0; $i < $count; $i += 5) {
            $deltaLine = $data[$i];
            $line += $deltaLine;
            $col = 0 === $deltaLine ? $col + $data[$i + 1] : $data[$i + 1];
            $length = $data[$i + 2];
            $type = $types[$data[$i + 3]] ?? null;
            // An index outside the legend is a server bug; skipping the token
            // keeps the WALK correct, which dropping the whole answer would not.
            if (null === $type || $length <= 0 || $line < 0 || $col < 0) {
                continue;
            }

            $byLine[$line][] = [
                'col' => $col,
                'endCol' => $col + $length,
                'type' => $type,
                'modifiers' => self::modifiersOf($data[$i + 4], $modifiers),
            ];
        }

        return $byLine;
    }

    /**
     * The modifier names a token's bitset names — bit N is the legend's Nth
     * entry.
     *
     * @param list<string> $legend
     *
     * @return list<string>
     */
    private static function modifiersOf(int $bits, array $legend): array
    {
        if (0 === $bits) {
            return [];
        }

        $names = [];
        foreach ($legend as $index => $name) {
            if (0 === ($bits & (1 << $index))) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * A legend, or [] if ANY entry is not a string.
     *
     * All-or-nothing for the same reason as the token stream: a legend is
     * POSITIONAL. Filtering one bad entry out shifts every name after it, so a
     * token pointing at index 2 would quietly resolve to the wrong type — worse
     * than not colouring at all, because it looks like an answer.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        /** @var list<string> $strings */
        $strings = \array_values(\array_filter($value, \is_string(...)));

        return \count($strings) === \count($value) ? $strings : [];
    }

    /**
     * The token stream, or [] if ANY entry is not an integer.
     *
     * All-or-nothing on purpose: the stream is positional, so silently dropping
     * one bad entry shifts every tuple after it and paints the rest of the file
     * with colours belonging to other symbols. Refusing the answer leaves the
     * lexer's colours, which are merely less informed rather than wrong.
     *
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        /** @var list<int> $ints */
        $ints = \array_values(\array_filter($value, \is_int(...)));

        return \count($ints) === \count($value) ? $ints : [];
    }
}
