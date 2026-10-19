<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * Host fonts for characters the bundled face lacks, tried in order: broad Unicode faces first,
 * then CJK and symbol faces on macOS, Linux and Windows. Color emoji fonts are absent, since GD
 * draws outlines only. A character no candidate covers keeps the bundled face.
 */
final class MermaidFallbackFonts
{
    public const array HOST = [
        '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
        '/Library/Fonts/Arial Unicode.ttf',
        '/System/Library/Fonts/Hiragino Sans GB.ttc',
        '/System/Library/Fonts/AppleSDGothicNeo.ttc',
        '/System/Library/Fonts/Apple Symbols.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/noto-cjk/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/noto/NotoSansSymbols2-Regular.ttf',
        '/usr/share/fonts/truetype/noto/NotoEmoji-Regular.ttf',
        '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
        'C:\Windows\Fonts\arialuni.ttf',
        'C:\Windows\Fonts\msyh.ttc',
        'C:\Windows\Fonts\seguisym.ttf',
    ];

    /** @var array<string, MermaidFontCoverage> */
    private array $coverages = [];

    /** @param list<string> $candidates */
    public function __construct(
        private readonly array $candidates = self::HOST,
    ) {}

    /** The first readable candidate that draws the character, else null. */
    public function font(int $codepoint): ?string
    {
        return \array_find($this->candidates, fn (string $path): bool => \is_readable($path) && $this->coverage($path)->covers($codepoint));
    }

    public function coverage(string $path): MermaidFontCoverage
    {
        return $this->coverages[$path] ??= new MermaidFontCoverage($path);
    }
}
