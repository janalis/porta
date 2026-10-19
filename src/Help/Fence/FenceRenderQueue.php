<?php

declare(strict_types=1);

namespace Porta\Help\Fence;

use Porta\Support\Scheduler;

/** Shared across pages. Work starts in scheduled callbacks, never in a paint. */
final class FenceRenderQueue
{
    private const int SLOTS = 2;

    /** @var list<FenceRequest> */
    private array $waiting = [];
    /** @var array<int, FenceRequest> */
    private array $running = [];
    private ?string $timer = null;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {}

    /**
     * @param \Closure(\Closure(FenceResult): void, \Closure(string): void): \Closure(): void $start
     * @param \Closure(FenceResult): void $success
     * @param \Closure(string): void $error
     * @return \Closure(): void
     */
    public function submit(\Closure $start, \Closure $success, \Closure $error): \Closure
    {
        $request = new FenceRequest($start, $success, $error);
        $this->waiting[] = $request;
        $this->schedule();

        return function () use ($request): void {
            if ($request->finished) {
                return;
            }
            $request->finished = true;
            unset($this->running[\spl_object_id($request)]);
            $this->waiting = \array_values(\array_filter(
                $this->waiting,
                static fn (FenceRequest $pending): bool => $request !== $pending,
            ));
            try {
                ($request->cancel ?? static function (): void {})();
            } catch (\Throwable) {
                // Cancellation has retired this consumer. A plugin's broken
                // handle must not interrupt cleanup of the remaining requests.
                return;
            } finally {
                $this->schedule();
            }
        };
    }

    private function schedule(): void
    {
        if ([] === $this->waiting && null !== $this->timer) {
            $this->scheduler->cancel($this->timer);
            $this->timer = null;
        }
        if (null !== $this->timer || [] === $this->waiting || \count($this->running) >= self::SLOTS) {
            return;
        }
        $this->timer = $this->scheduler->delay(0.0, function (): void {
            $this->timer = null;
            $this->drain();
        });
    }

    private function drain(): void
    {
        while ([] !== $this->waiting && \count($this->running) < self::SLOTS) {
            $request = \array_shift($this->waiting);
            $this->running[\spl_object_id($request)] = $request;
            $success = function (FenceResult $result) use ($request): void {
                if ($this->finish($request)) {
                    ($request->success)($result);
                }
            };
            $error = function (string $message) use ($request): void {
                if ($this->finish($request)) {
                    ($request->error)($message);
                }
            };
            try {
                $cancel = ($request->start)($success, $error);
                // A callback may complete synchronously or cancel itself before
                // start() returns its cancellation handle.
                if ($request->finished) {
                    $cancel();
                    continue;
                }
                $request->cancel = $cancel;
            } catch (\Throwable $failure) {
                $error($failure->getMessage());
            }
        }
    }

    private function finish(FenceRequest $request): bool
    {
        if ($request->finished) {
            return false;
        }
        $request->finished = true;
        unset($this->running[\spl_object_id($request)]);
        $this->schedule();

        return true;
    }
}
