<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * Whether syntax highlighting emits ANSI colour or plain text. String-backed so
 * the container can resolve it straight from the `PORTA_ANSI` env flag ('1'/'0')
 * via the `enum` env-var processor.
 */
enum ColorMode: string
{
    case Plain = '0';
    case Ansi = '1';
}
