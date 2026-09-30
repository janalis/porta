<?php

declare(strict_types=1);

namespace Porta\Workbench;

/** What an agent is doing to a file, as {@see \Porta\Event\AgentActivity} reports it. */
enum AgentActivityKind: string
{
    case Reading = 'reading';
    case Editing = 'editing';
}
