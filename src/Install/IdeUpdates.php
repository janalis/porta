<?php

declare(strict_types=1);

namespace Porta\Install;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Plugin\Store\HttpFetch;
use Porta\Plugin\Tool\ToolUpdates;
use Porta\Support\Decoded;
use Porta\Support\HourlyLook;
use Porta\Support\ProcessEnvironment;
use Porta\Workbench\Notifier;
use Porta\Workbench\UpdateRestart;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Discover stable Porta releases without modifying a running installation. */
#[AsIdeService]
final class IdeUpdates
{
    public const array CADENCES = ToolUpdates::CADENCES;
    public const string RELEASE = 'https://api.github.com/repos/janalis/porta/releases/latest';

    private string $cadence;
    private bool $auto;
    private bool $checking = false;
    private ?string $handled = null;
    private readonly HourlyLook $hourly;
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param array<string, int|bool|string> $settings
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        #[Autowire('%env(json:PORTA_SETTINGS)%')]
        array $settings,
        private readonly HttpFetch $http,
        private readonly IdeUpdateState $state,
        private readonly Installation $installation,
        private readonly UpdateRestart $restart,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        ?\Closure $clock = null,
        #[Autowire('%env(PORTA_VERSION)%')]
        private readonly string $version = '0.0.0',
        #[Autowire('%env(default::PORTA_RESTART_CHECK_AFTER)%')]
        private readonly ?string $restartCheckAfter = null,
    ) {
        $cadence = $settings['ideUpdateCheck'] ?? 'daily';
        $this->cadence = \is_string($cadence) && \array_key_exists($cadence, self::CADENCES) ? $cadence : 'daily';
        $this->auto = true === ($settings['ideAutoUpdate'] ?? false);
        $this->clock = $clock ?? \time(...);
        $this->hourly = new HourlyLook($this->checkIfDue(...));
    }

    public function start(): void
    {
        $this->hourly->start();
    }

    public function stop(): void
    {
        $this->hourly->stop();
    }

    public function setCadence(string $cadence): void
    {
        $this->cadence = \array_key_exists($cadence, self::CADENCES) ? $cadence : 'daily';
        $this->checkIfDue();
    }

    // @mago-expect lint:no-boolean-flag-parameter -- This is the persisted automatic-update preference, with notification state reset when it changes.
    public function setAuto(bool $auto): void
    {
        if ($this->auto !== $auto) {
            $this->handled = null;
        }
        $this->auto = $auto;
    }

    public function checkIfDue(): void
    {
        $every = self::CADENCES[$this->cadence];
        $resumeAfter = ProcessEnvironment::restartCheckAfter($this->restartCheckAfter);
        if (($this->clock)() < $resumeAfter || 0 === $every || $this->checking) {
            return;
        }
        if (!$this->state->due(($this->clock)(), $every)) {
            $this->offer($this->state->latest());

            return;
        }
        $this->checking = true;
        $this->http->get(
            [self::RELEASE],
            function (array $answers): void {
                $this->checking = false;
                $data = Decoded::json($answers[self::RELEASE] ?? '');
                $version = \is_string($data['tag_name'] ?? null) ? $data['tag_name'] : '';
                $stable = true !== ($data['prerelease'] ?? false) && true !== ($data['draft'] ?? false) && 1 === \preg_match('/^v?\d+\.\d+\.\d+$/D', $version);
                $this->state->checked(($this->clock)(), $stable ? $version : null);
                $this->offer($stable ? $version : $this->state->latest());
            },
            maxBytes: 1_048_576,
        );
    }

    private function offer(?string $version): void
    {
        if (
            null === $version
            || $version === $this->handled
            || 1 !== \preg_match('/^v?\d+\.\d+\.\d+$/D', $version)
            || !\version_compare(\ltrim($version, characters: 'v'), \ltrim($this->version, characters: 'v'), operator: '>')
        ) {
            return;
        }
        $this->handled = $version;
        if ($this->auto && $this->installation->method->updatesItself()) {
            $this->restart->core();

            return;
        }
        $this->notifier->flash($this->translator->trans('Porta %version% is available. Update from Settings › Command line.', ['%version%' => $version]), 8.0, 'ide-updates');
    }
}
