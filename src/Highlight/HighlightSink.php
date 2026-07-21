<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * Where a {@see SyntaxHighlightProvider} hands its answer back.
 *
 * There is deliberately **one** delivery path for both kinds of backend: a
 * synchronous one calls {@see deliver()} before `highlight()` returns, an
 * asynchronous one calls it later from an event-loop callback. Core tells them
 * apart by whether the cache moved by the time the call returned, so there is a
 * single state machine rather than two.
 *
 * `$markup` is the whole document as one string — not a line array — because
 * the line-count guard and the SGR re-open pass that make each line
 * independently renderable are correctness-critical and must live in exactly
 * one place. A provider that wants per-line output just implodes.
 */
interface HighlightSink
{
    /** ANSI-marked text for ($documentId, $version). Late or unsolicited answers are dropped. */
    public function deliver(string $documentId, int $version, string $markup): void;

    /** The backend gave up; core keeps whatever it is currently painting. */
    public function fail(string $documentId, int $version, \Throwable $error): void;
}
