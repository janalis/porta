<?php

declare(strict_types=1);

/**
 * A server that ignores SIGTERM, for the reap-without-blocking regression:
 * JsonRpcTransport::stop() must not proc_close() (block until exit) on a
 * child that shrugs off termination. Bounded lifetime so a leaked child
 * cannot outlive the test run.
 */
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
sleep(1);
