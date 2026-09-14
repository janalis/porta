<?php

declare(strict_types=1);

namespace Porta\Bootstrap;

use Porta\Config\IdeConfig;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Workbench\Notifier;

/**
 * The last step of the Ide ctor-tail composition, run once every service
 * exists: hands the plugin handlers their live runtime seam (so flash / editor
 * access / openFile resolve at call time) and surfaces the config-parse and
 * plugin-load warnings as toasts, and starts the install of any enabled plugin
 * whose composer packages are missing. A sibling of {@see WidgetHooks} — kept
 * separate so neither grows an unwieldy constructor. One wire() call from Ide.
 */
#[AsIdeService]
final readonly class StartupWiring
{
    public function __construct(
        private PluginManager $plugins,
        private PluginRuntime $runtime,
        private IdeConfig $config,
        private Notifier $notifier,
        private PluginLifecycle $lifecycle,
        private Translator $translator,
    ) {}

    public function wire(): void
    {
        // Everything plugin handlers reach exists now — hand them the live seam.
        $this->plugins->wireRuntime($this->runtime);
        foreach ([...$this->config->warnings, ...$this->plugins->warnings] as $warning) {
            $this->notifier->flash($this->translator->trans($warning->key, $warning->params), 5.0);
        }
        // Enabled plugins whose packages are missing: boot recorded them rather
        // than shelling out mid-discovery. The subprocesses are armed here and
        // run once the loop starts.
        $this->lifecycle->installPending();
    }
}
