<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\PluginApi;

/**
 * Every public method of {@see PluginApi} is in `docs/plugins.md`.
 *
 * Eight were not, and six of those are called by the plugins this repo SHIPS:
 * `registerHighlighter()` (tempest), `syntaxTheme()` (tempest, git),
 * `setChangeMarks()`, `copyToClipboard()`, `onFileReloaded()` (git) and
 * `onFileRewritten()` (local-history). A third-party author reading the
 * reference could not discover how to do what the bundled plugins do.
 *
 * `docs/plugins.md` is the contract for third-party code — {@see
 * \Porta\Tests\Unit\Event\PluginObservableContractTest} already holds its event
 * table to the same rule. This holds the method surface.
 *
 * Mentioned ANYWHERE on the page, not necessarily in the reference table:
 * `registerPane()` and `registerViewer()` are taught by a worked example under
 * "Providing a pane", which is the better place for them, and a gate that
 * demanded a table row would be arguing about form rather than coverage.
 */
final class PluginApiDocTest extends TestCase
{
    /**
     * Methods that are public because the RUNTIME calls them, not a plugin.
     *
     * The `buffered*` family is how the loader drains what `activate()`
     * registered; `pluginName()` is how it labels the result. They sit under
     * PluginApi's own "Loader/manager plumbing" banner, and documenting them
     * would invite a plugin to call them.
     *
     * @return list<string>
     */
    private static function plumbing(): array
    {
        $internal = ['pluginName'];
        foreach (\get_class_methods(PluginApi::class) as $method) {
            if (!\str_starts_with($method, 'buffered')) {
                continue;
            }

            $internal[] = $method;
        }
        \sort($internal);

        return $internal;
    }

    public function testEveryPublicApiMethodIsDocumented(): void
    {
        $doc = (string) \file_get_contents(__DIR__.'/../../../docs/plugins.md');
        $plumbing = self::plumbing();

        $missing = [];
        foreach (new \ReflectionClass(PluginApi::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if ($method->isConstructor() || \in_array($name, $plumbing, strict: true)) {
                continue;
            }
            if (\str_contains($doc, $name.'(')) {
                continue;
            }

            $missing[] = $name;
        }
        \sort($missing);

        $this->assertSame([], $missing, 'these are public API and docs/plugins.md never names them');
    }

    /**
     * And the other direction: the plumbing list may not quietly grow to cover
     * a method someone simply did not want to write up.
     */
    public function testThePlumbingListIsExactlyTheLoaderSurface(): void
    {
        $this->assertSame(
            [
                'bufferedActions',
                'bufferedCommands',
                'bufferedDebugAdapters',
                'bufferedHighlighters',
                'bufferedHooks',
                'bufferedInstallers',
                'bufferedLanguageServers',
                'bufferedListTools',
                'bufferedMenuItems',
                'bufferedMenus',
                'bufferedPanes',
                'bufferedSettingHooks',
                'bufferedSettings',
                'bufferedStatusSegments',
                'bufferedTokenSpans',
                'bufferedTools',
                'bufferedViewers',
                'bufferedViews',
                'pluginName',
            ],
            self::plumbing(),
            'the loader surface changed: a new buffered* drain is fine, anything else needs documenting instead',
        );
    }
}
