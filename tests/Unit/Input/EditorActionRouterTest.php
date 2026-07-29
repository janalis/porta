<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Input\EditorActionRouter;
use Porta\Tests\Support\Probe;
use Porta\Widget\EditorCallbacks;

/**
 * The single-source contract of the router's two handler maps: their KEYS
 * drive both membership and dispatch, so every key must name a catalog action
 * — an id in a map but not in {@see ActionCatalog::ACTIONS} would be
 * unrebindable (and invisible to the keymap page), while the docblock's
 * promise that "an id can never be routable-but-unhandled" was, until now,
 * enforced by nothing.
 */
final class EditorActionRouterTest extends TestCase
{
    public function testEveryHandlerMapKeyIsACatalogAction(): void
    {
        $router = new EditorActionRouter(
            static fn (): EditorCallbacks => new EditorCallbacks(),
            static fn (): array => [0, 0],
        );

        $maps = [];
        foreach (['simpleHandlers', 'positionalHandlers'] as $method) {
            $maps[$method] = Probe::invoke($router, $method);
        }

        $overlap = array_intersect(array_keys($maps['simpleHandlers']), array_keys($maps['positionalHandlers']));
        $this->assertSame([], $overlap, 'an action id lives in exactly one of the two maps');

        foreach ($maps as $method => $handlers) {
            $this->assertNotSame([], $handlers, $method.' is non-empty');
            foreach (array_keys($handlers) as $action) {
                $this->assertArrayHasKey($action, ActionCatalog::ACTIONS, $method.': "'.$action.'" is a catalog action (rebindable, visible in the keymap page)');
            }
        }
    }
}
