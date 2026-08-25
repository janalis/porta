<?php

declare(strict_types=1);

namespace Porta\Onboarding;

use Porta\Support\JsonFile;

/**
 * Answers one question for the Welcome screen: is this project a Symfony
 * application?
 *
 * The signal is a `symfony/framework-bundle` requirement in the project's own
 * composer.json — the same one symfony-lsp's health check uses. Individual
 * `symfony/*` components deliberately do not count: half the PHP ecosystem
 * depends on symfony/console without being a Symfony app, and the language
 * server's features (routes, container, config) only exist against the
 * framework. Re-read on every Welcome open (steps are rebuilt each time), so
 * a `composer require` mid-session is picked up by the next open.
 */
final class SymfonyDetector
{
    public function __construct(
        private readonly string $rootDir,
    ) {}

    public function isSymfonyProject(): bool
    {
        /** @var array{require?: array<string, mixed>, require-dev?: array<string, mixed>}|null $composer */
        $composer = JsonFile::read($this->rootDir.'/composer.json');
        if (null === $composer) {
            return false;
        }
        foreach (['require', 'require-dev'] as $section) {
            $packages = $composer[$section] ?? null;
            if (\is_array($packages) && \array_key_exists('symfony/framework-bundle', $packages)) {
                return true;
            }
        }

        return false;
    }
}
