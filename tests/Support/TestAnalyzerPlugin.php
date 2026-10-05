<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Plugin\Api\AbstractAnalyzerPlugin;
use Porta\Plugin\Api\PluginApi;

/**
 * A concrete analyzer: its report shape is a flat `rows` list, it accepts
 * exit code 0 only, and it deliberately does NOT override noun() — the
 * default wording is what AbstractAnalyzerPluginTest's flashes assert.
 */
final class TestAnalyzerPlugin extends AbstractAnalyzerPlugin
{
    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api, [['composer', 'demo/demo']]);
        $this->registerAnalysis($api, 'Demo', ['ctrl+alt+d']);
    }

    protected function command(string $binary, string $file): array
    {
        return [$binary, $file];
    }

    protected function acceptsExitCode(int $code): bool
    {
        return 0 === $code;
    }

    protected function diagnostics(array $report): array
    {
        return \array_map(
            static fn (array $row): array => [
                'line' => (int) $row['line'],
                'message' => (string) $row['message'],
            ],
            self::rowsAt($report, 'rows'),
        );
    }

    /** Reaches the shared JSON walkers, which are protected for the plugins that use them. */
    public function walk(mixed $data, string $key): array
    {
        return [self::arrayAt($data, $key), self::rowsAt($data, $key)];
    }
}
