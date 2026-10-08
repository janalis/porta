<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Markdown;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Markdown\MarkdownParser;
use Porta\Tests\Support\Markdown\HtmlOracle;
use Porta\Tests\Support\Markdown\SpecExamples;

/**
 * Every example of the CommonMark 0.31.2 spec, and every GFM extension
 * example of GitHub's spec, parsed and written back as the HTML the spec
 * expects. The parser exists so the preview shows what GitHub shows; the
 * spec is how "what GitHub shows" is measured.
 */
final class SpecTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Support/fixtures/markdown/';

    /**
     * Where GitHub and plain CommonMark part ways, the test holds the parser to
     * GitHub: GFM links a bare URL or email address in running text, which
     * these CommonMark examples (run by cmark-gfm with its extensions off)
     * expect to stay text.
     */
    private const array GITHUB_DIFFERS = [
        '#608 Autolinks' => "<p>&lt; <a href=\"https://foo.bar\">https://foo.bar</a> &gt;</p>\n",
        '#611 Autolinks' => "<p><a href=\"https://example.com\">https://example.com</a></p>\n",
        '#612 Autolinks' => "<p><a href=\"mailto:foo@bar.example.com\">foo@bar.example.com</a></p>\n",
    ];

    /** @return iterable<string, array{string, string, string}> */
    public static function commonMark(): iterable
    {
        $examples = SpecExamples::read(self::FIXTURES.'commonmark-0.31.2.txt');
        foreach (self::GITHUB_DIFFERS as $key => $html) {
            $examples[$key][1] = $html;
        }

        return $examples;
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function gfm(): iterable
    {
        return SpecExamples::read(self::FIXTURES.'gfm-0.29.txt', ['table', 'strikethrough', 'autolink', 'tagfilter', 'disabled']);
    }

    #[DataProvider('commonMark')]
    public function testCommonMarkExample(string $markdown, string $html, string $section): void
    {
        $this->assertSame($html, new HtmlOracle()->render(new MarkdownParser()->parse($markdown)), $section."\n".$markdown);
    }

    #[DataProvider('gfm')]
    public function testGfmExtensionExample(string $markdown, string $html, string $section): void
    {
        $this->assertSame($html, new HtmlOracle(tagFilter: true)->render(new MarkdownParser()->parse($markdown)), $section."\n".$markdown);
    }
}
