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
// Announce it on stderr, which JsonRpcTransport forwards to its logger: a test
// that terminates this child BEFORE the handler is installed exercises the
// default disposition (die on SIGTERM), and so the trivial "already dead"
// branch of the reaper rather than the poll-until-dead one it means to test.
// The alternative was for the caller to sleep and hope.
fwrite(STDERR, data: "sigterm-ignored\n");
sleep(1);
