<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The live templates in effect for a language: the built-in set from
 * {@see TemplateCatalog}, with `.porta.json`'s `languages.<id>.templates`
 * layered over it.
 *
 * A user entry with the same abbreviation REPLACES the built-in, and one with an
 * empty body REMOVES it — which is the point of the empty case surviving
 * validation: a shipped abbreviation you happen to type as ordinary code has to
 * be switchable off, and there is no settings row for a map.
 */
#[AsIdeService]
final readonly class Templates
{
    /**
     * @param array<string, array{templates?: array<string, string>}> $languages the `languages` config block
     */
    public function __construct(
        #[Autowire('%env(json:PORTA_LANGUAGES)%')]
        private array $languages = [],
    ) {}

    /** @return array<string, string> abbreviation => snippet body */
    public function forLanguage(string $languageId): array
    {
        $templates = TemplateCatalog::forLanguage($languageId);
        foreach ($this->languages[$languageId]['templates'] ?? [] as $abbreviation => $body) {
            if ('' === $body) {
                unset($templates[$abbreviation]);

                continue;
            }
            $templates[$abbreviation] = $body;
        }

        return $templates;
    }
}
