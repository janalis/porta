<?php

declare(strict_types=1);

namespace Porta\Graph;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Support\AsyncProcess;
use Porta\Workbench\Notifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Tui;

/**
 * "Show Project Architecture": produce the project's architecture DOT —
 * the configured `architectureCommand`'s stdout when set (any language, any
 * generator), else the built-in {@see NamespaceGraphScanner} — write it to
 * `.porta/architecture.dot` (a machine artifact, per the settings-store
 * split), and open/reuse the graph tab on it. Being file-backed is the whole
 * trick: reuse is the ordinary path-keyed buffer lookup, the tab title rides
 * {@see BufferService}'s artifact-path match, and the 1s filesystem
 * reconciler re-renders external regenerations for free.
 */
#[AsIdeService]
final class ArchitectureGraphController
{
    private ?AsyncProcess $running = null;

    public function __construct(
        private readonly NamespaceGraphScanner $scanner,
        private readonly BufferService $bufferService,
        private readonly BufferManager $buffers,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly Tui $tui,
        private readonly string $rootDir,
        // The "Architecture command" setting; '' resolves to null (built-in scanner).
        #[Autowire('%env(default::PORTA_ARCHITECTURE_COMMAND)%')]
        private readonly ?string $architectureCommand = null,
    ) {}

    public static function dotPath(string $rootDir): string
    {
        return $rootDir.'/.porta/architecture.dot';
    }

    public function open(): void
    {
        if (null === $this->architectureCommand || '' === $this->architectureCommand) {
            $this->publish($this->scanner->scan());

            return;
        }
        // The configured generator runs at project trust level, like runCommand.
        $this->running?->cancel();
        $process = new AsyncProcess(['/bin/sh', '-c', $this->architectureCommand], $this->rootDir, captureStderr: true);
        $this->running = $process;
        $started = $process->start(function (int $code, string $stdout) use ($process): void {
            if ($this->running === $process) {
                $this->running = null;
            }
            if (0 !== $code) {
                $stderr = trim($process->errorOutput());
                $this->notifier->error($this->translator->trans('Architecture command failed: %error%', ['%error%' => '' !== $stderr ? $stderr : "exit {$code}"]));

                return;
            }
            if ('' === trim($stdout)) {
                $this->notifier->error($this->translator->trans('Architecture command produced no output'));

                return;
            }
            $this->publish($stdout);
        });
        if (!$started) {
            // proc_open spawns even unusable binaries — unreachable in tests.
            // @codeCoverageIgnoreStart
            $this->running = null;
            $this->notifier->error($this->translator->trans('Architecture command failed: %error%', ['%error%' => 'could not start']));

            // @codeCoverageIgnoreEnd
        }
    }

    /** Write the artifact and open (or refresh) its tab. */
    private function publish(string $dot): void
    {
        $path = self::dotPath($this->rootDir);
        $dir = \dirname($path);
        // @mago-expect lint:no-error-control-operator -- a concurrent mkdir (or a file squatting on the name) warns; the is_dir recheck handles both.
        if (!is_dir($dir) && !@mkdir($dir, permissions: 0o755) && !is_dir($dir)) {
            $this->notifier->error($this->translator->trans('Cannot write %file%', ['%file%' => '.porta/architecture.dot']));

            return;
        }
        file_put_contents($path, $dot);
        // Buffers key canonical paths (openFile realpaths), so the reuse lookup must too.
        $path = ($resolved = realpath($path)) ? $resolved : $path;
        $existing = $this->buffers->get($path);
        if (null !== $existing) {
            // The tab is open: refresh its payload in place and re-stamp so the
            // 1s poller does not flash a second "Reloaded" for our own write.
            $this->buffers->openGraph($path, $dot);
            $existing->stampFromDisk();
            $this->bufferService->showBuffer($existing);
            $this->bufferService->focusEditorArea();
            $this->tui->requestRender();

            return;
        }
        $this->bufferService->openFile($path, OpenFocus::Editor);
    }
}
