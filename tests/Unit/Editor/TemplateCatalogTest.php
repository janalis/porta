<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\SnippetParser;
use Porta\Editor\TemplateCatalog;
use Porta\Editor\TemplateExpander;

final class TemplateCatalogTest extends TestCase
{
    /** @return \Generator<string, array{string}> */
    public static function languagesWithTemplates(): \Generator
    {
        yield 'php' => ['php'];
        yield 'javascript' => ['javascript'];
        yield 'typescript' => ['typescript'];
    }

    public function testAnUnknownLanguageHasNoTemplates(): void
    {
        $this->assertSame([], TemplateCatalog::forLanguage('markdown'));
        $this->assertSame([], TemplateCatalog::forLanguage(''));
    }

    public function testTypescriptSharesJavascriptsSet(): void
    {
        $this->assertSame(TemplateCatalog::forLanguage('javascript'), TemplateCatalog::forLanguage('typescript'));
    }

    /**
     * Every body must parse into text plus at least one stop — a template that
     * expands with the caret left at the end is a snippet nobody can fill in,
     * and a typo in the snippet syntax shows up exactly here.
     */
    #[DataProvider('languagesWithTemplates')]
    public function testEveryTemplateParsesIntoANavigableSnippet(string $language): void
    {
        $templates = TemplateCatalog::forLanguage($language);
        $this->assertNotSame([], $templates);

        foreach ($templates as $abbreviation => $body) {
            $parsed = SnippetParser::parse(\str_replace(TemplateCatalog::EXPRESSION, replace: '$x', subject: $body));
            $this->assertNotSame('', $parsed['text'], "{$abbreviation} expands to nothing");
            $this->assertNotSame([], $parsed['stops'], "{$abbreviation} has no tab stop, so the caret is left nowhere useful");
        }
    }

    /** A postfix body must actually USE the expression, or the dot form is a lie. */
    #[DataProvider('languagesWithTemplates')]
    public function testEveryPostfixTemplateSubstitutesTheExpression(string $language): void
    {
        foreach (TemplateCatalog::forLanguage($language) as $abbreviation => $body) {
            if (!\str_starts_with($abbreviation, '.')) {
                continue;
            }
            $this->assertStringContainsString(TemplateCatalog::EXPRESSION, $body, "{$abbreviation} is postfix but drops its expression");
        }
    }

    /** And a plain one must NOT, or it would expand with a literal token in it. */
    #[DataProvider('languagesWithTemplates')]
    public function testNoPlainTemplateCarriesTheExpressionToken(string $language): void
    {
        foreach (TemplateCatalog::forLanguage($language) as $abbreviation => $body) {
            if (\str_starts_with($abbreviation, '.')) {
                continue;
            }
            $this->assertStringNotContainsString(TemplateCatalog::EXPRESSION, $body, "{$abbreviation} has no expression to substitute");
        }
    }

    /**
     * Every shipped abbreviation must actually be reachable: typing it and
     * asking the expander has to find THAT template, not a longer one that ends
     * with the same letters.
     */
    #[DataProvider('languagesWithTemplates')]
    public function testEveryShippedAbbreviationIsReachable(string $language): void
    {
        $templates = TemplateCatalog::forLanguage($language);
        foreach ($templates as $abbreviation => $body) {
            $line = \str_starts_with($abbreviation, '.') ? '$x'.$abbreviation : $abbreviation;
            $found = TemplateExpander::at($line, \strlen($line), $templates);

            $this->assertNotNull($found, "{$abbreviation} cannot be typed at all");
            $this->assertSame(
                \str_replace(TemplateCatalog::EXPRESSION, replace: '$x', subject: $body),
                $found['snippet'],
                "typing {$abbreviation} expands some other template",
            );
        }
    }
}
