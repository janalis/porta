<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * Which bundled plugin Porta recommends for each job on a new PHP project.
 *
 * This is core's PRODUCT OPINION, not leaked plugin knowledge, and it cannot be
 * anything else: a bundled plugin's capabilities are unknowable without loading
 * it, and the plugins worth recommending are exactly the ones that are OFF —
 * so they have not loaded and cannot announce themselves. (A
 * `registerWelcomeStep()` seam was designed and rejected on that: the only
 * cards it could produce are ones already ticked.)
 *
 * What it does fix is that the opinion used to be spelled in three places —
 * four constants on the first-run screen and one borrowed from a settings
 * controller — so changing Porta's recommendation meant finding them all. An
 * enum rather than constants: the first-run screen's helpers take a
 * recommendation, not any plugin name, and a case's `value` is the name only
 * where it leaves for the plugin lifecycle or a label.
 *
 * It lives in `Config` rather than beside the first-run screen because it has
 * two consumers in different namespaces, and this is where the other
 * plugins-this-project-runs fact already lives ({@see IdeConfig::DEFAULT_ENABLED_PLUGINS}
 * — a different list: what is ON by default, not what is RECOMMENDED).
 */
enum RecommendedPlugin: string
{
    /** The bundled syntax-highlighting engine. */
    case Highlighter = 'tempest';

    /** The bundled PHP language server. */
    case LanguageServer = 'phpantom';

    /**
     * The bundled Symfony language server, offered only when the project IS a
     * Symfony application ({@see \Porta\Onboarding\SymfonyDetector}) — it runs
     * beside the PHP server, serving twig/yaml/xml/json/dotenv.
     */
    case SymfonyLanguageServer = 'symfony-lsp';

    /** The bundled formatter. */
    case Formatter = 'php-cs-fixer';

    /** The bundled analyzer — one lint step keeps the first-run screen a signpost; the others stay in Settings → Plugins. */
    case Analyzer = 'phpstan';

    /**
     * The one plugin that serves BOTH tool jobs when the project ships mago
     * ({@see \Porta\Onboarding\MagoDetector}). The steps then *target* mago: an already-enabled
     * php-cs-fixer or phpstan does not satisfy them, because the offer is
     * "enable the recommended tool", not "have any tool".
     */
    case BothTools = 'mago';
}
