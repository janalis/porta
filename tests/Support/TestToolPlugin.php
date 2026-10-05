<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;

/**
 * A concrete tool plugin exposing the protected surface for assertions.
 * `runsProjectCode` stands for a tool that executes the project's PHP.
 */
final class TestToolPlugin extends AbstractToolPlugin
{
    public function __construct(
        string $name,
        string $binaryName,
        BinarySearch $search = BinarySearch::VendorBinThenPath,
        private readonly bool $runsProjectCode = false,
    ) {
        parent::__construct($name, $binaryName, $search);
    }

    #[\Override]
    protected function runsProjectCode(): bool
    {
        return $this->runsProjectCode;
    }

    public function activate(PluginApi $api): void
    {
        // PluginApi::setting() refuses unregistered keys, so the base
        // class's `binary` row must exist before binary() reads it —
        // which is exactly what registerBinarySetting() does.
        $this->registerBinarySetting($api, [['composer', 'demo/demo']]);
    }

    public function callBinary(PluginApi $api): ?string
    {
        return $this->binary($api);
    }

    public function callAcquire(PluginApi $api): ?string
    {
        return $this->acquire($api);
    }

    public function callRelease(): void
    {
        $this->release();
    }

    public function callActiveFile(PluginApi $api, string $extension = '.php'): ?string
    {
        return $this->activeFileOrFlash($api, $extension);
    }

    public function callAvailable(PluginApi $api): \Closure
    {
        return $this->binaryAvailable($api);
    }

    public function callApplyFormatted(PluginApi $api, string $file, ?string $before): void
    {
        $this->applyFormatted($api, $file, $before);
    }

    public function callTextBefore(PluginApi $api, string $file): ?string
    {
        return $this->textBefore($api, $file);
    }

    /** @param list<array{string, string}> $via */
    public function callRegisterBinarySetting(PluginApi $api, array $via): void
    {
        $this->registerBinarySetting($api, $via);
    }

    public function callRegisterToggle(PluginApi $api, string $id, string $label, string $key, string $phrase): void
    {
        $this->registerToggle($api, $id, $label, $key, $phrase);
    }

    public function callFlashExit(PluginApi $api, int $code, string $success): void
    {
        $this->flashExit($api, $code, $success);
    }

    public function callFlashCount(PluginApi $api, int $count, string $noun, string $file): void
    {
        $this->flashCount($api, $count, $noun, $file);
    }

    /** @param list<string> $arguments */
    public function callRunAndApply(PluginApi $api, array $arguments, string $success): void
    {
        $this->runAndApply($api, $arguments, $success);
    }
}
