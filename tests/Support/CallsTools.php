<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ToolCaller;

/**
 * Call a tool a plugin registered with {@see PluginApi::registerTool()} the way
 * its server does: in a fiber of its own, pumping the loop until it answers —
 * so a handler that waits on a subprocess is tested waiting, not stubbed.
 *
 * Read off the plugin's BUFFERED registrations, so no IDE and no mcp plugin
 * are needed: what is under test is the tool, not who serves it.
 */
trait CallsTools
{
    /** Satisfied by {@see PumpsLoop::await()} on the composing class. */
    abstract private function await(callable $done, float $timeout = 10.0, string $what = 'condition'): void;

    /**
     * The handler's answer; what it threw is thrown here.
     *
     * @param array<string, mixed> $arguments
     *
     * @return string|array<array-key, mixed>
     */
    private function callTool(PluginApi $api, string $id, array $arguments = []): string|array
    {
        $tool = \array_find($api->bufferedTools(), static fn (array $tool): bool => $id === $tool['id']);
        \assert(null !== $tool, description: 'the plugin registered a tool "'.$id.'"');
        // Caught INSIDE the fiber, as the server does: a throw after a resume
        // would otherwise surface in the loop, not here.
        $fiber = new \Fiber(static function () use ($tool, $arguments): array {
            try {
                return ['answer' => $tool['handler']($arguments, new ToolCaller('test', 'Test agent'))];
            } catch (\Throwable $error) {
                return ['thrown' => $error];
            }
        });
        $fiber->start();
        // @mago-expect lint:prefer-first-class-callable -- `$fiber->isTerminated(...)` is what lint asks for, and the analyzer refuses it as await()'s callable
        $this->await(
            static function () use ($fiber): bool {
                return $fiber->isTerminated();
            },
            what: 'the "'.$id.'" tool to answer',
        );
        /** @var array{answer?: string|array<array-key, mixed>, thrown?: \Throwable} $outcome */
        $outcome = $fiber->getReturn();
        if (\array_key_exists('thrown', $outcome)) {
            throw $outcome['thrown'];
        }

        return $outcome['answer'] ?? '';
    }
}
