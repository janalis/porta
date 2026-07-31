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
 * controller — so changing Porta's recommendation meant finding them all.
 *
 * It lives in `Config` rather than beside the first-run screen because it has
 * two consumers in different namespaces, and this is where the other
 * plugins-this-project-runs fact already lives ({@see IdeConfig::DEFAULT_ENABLED_PLUGINS}
 * — a different list: what is ON by default, not what is RECOMMENDED).
 */
final class RecommendedPlugins
{
    /** The bundled syntax-highlighting engine. */
    public const string HIGHLIGHTER = 'tempest';

    /** The bundled PHP language server. */
    public const string LANGUAGE_SERVER = 'phpantom';

    /** The bundled formatter. */
    public const string FORMATTER = 'php-cs-fixer';

    /** The bundled analyzer — one lint step keeps the first-run screen a signpost; the others stay in Settings → Plugins. */
    public const string ANALYZER = 'phpstan';

    /**
     * The one plugin that serves BOTH tool jobs when the project ships mago
     * ({@see \Porta\Onboarding\MagoDetector}). The steps then *target* mago: an already-enabled
     * php-cs-fixer or phpstan does not satisfy them, because the offer is
     * "enable the recommended tool", not "have any tool".
     */
    public const string BOTH_TOOLS = 'mago';
}
