<?php

declare(strict_types=1);

use Porta\Custos\ServerConfig;
use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ServerNotFound;
use Porta\Plugin\Api\ServerRole;

/**
 * custos, the PHP inspector and fixer — one native Go binary, so
 * {@see BinarySearch::PathOnly}: the `binary` setting, then the PATH. No
 * package manager ships it, so there is no install offer.
 *
 * `custos lsp` is registered for php as {@see ServerRole::Supplementary}: it
 * is an inspector, not a general PHP server, so it runs BESIDE phpantom or
 * phpactor. Its diagnostics are kept apart from theirs and its quick-fixes
 * (lazily resolved) and its "fix all problems in file" action are merged into
 * the primary's Alt+Enter list, with `codeAction/resolve` routed back to it.
 * The `php` and `comparisonStyle` settings travel as `initializationOptions`,
 * re-read on every LSP (re)configure — a settings edit relaunches the server —
 * and only when set: blank leaves the project's custos.json/composer.json in
 * charge.
 *
 * "Custos: Fix File" runs `custos fix <file>`, which edits in place, with the
 * project root as its config directory (where the server reads it too) and
 * the same `php`/`comparisonStyle` overrides, and applies the result into the buffer as one undo
 * step. A dirty buffer is refused: the fix reads the file on disk.
 */
return new class('custos', 'custos', BinarySearch::PathOnly) extends AbstractToolPlugin {
    public function activate(PluginApi $api): void
    {
        $config = new ServerConfig();
        $this->registerBinarySetting($api);
        $api->registerSetting('php', 'Target PHP version', 'text', '', hint: 'e.g. 8.3; empty = the project config (custos.json, composer.json)');
        $api->registerSetting(
            'comparisonStyle',
            'Comparison style',
            'choice',
            ServerConfig::PROJECT,
            ServerConfig::COMPARISON_STYLES,
            hint: 'project: whatever the project config says · regular: $x === null · yoda: null === $x',
        );

        $api->registerLanguageServer(
            'php',
            'custos',
            fn (): array|ServerNotFound => $config->command($this->binary($api)),
            static fn (): array => $config->initializationOptions((string) $api->setting('php'), (string) $api->setting('comparisonStyle')),
            ServerRole::Supplementary,
        );

        $api->registerCommand('fix', 'Custos: Fix File', function () use ($api, $config): void {
            $file = $this->activeFileOrFlash($api);
            if (null === $file) {
                return;
            }
            // `custos fix` rewrites the file ON DISK, and the result is then
            // put into the buffer. Run over a dirty buffer, it would fix the
            // last SAVED text and that would replace the unsaved edits.
            // Saving on the user's behalf is no better: it fires every other
            // on-save tool against the file custos is about to rewrite.
            if (array_any($api->openBuffers()->files(), static fn (array $tab): bool => $tab['path'] === $file && $tab['dirty'])) {
                $api->flash($api->trans('%tool%: save %file% first — the fix runs on the file on disk', ['%tool%' => $this->name(), '%file%' => basename($file)]), 4.0);

                return;
            }
            $this->runAndApply(
                $api,
                $config->fixArguments($file, $api->projectRoot(), (string) $api->setting('php'), (string) $api->setting('comparisonStyle')),
                $api->trans('%tool%: fixed %file%', ['%tool%' => $this->name(), '%file%' => basename($file)]),
                $file,
            );
        });
        $api->addMenuItem('fix', $this->binaryAvailable($api));
    }
};
