<?php

declare(strict_types=1);

namespace Porta\Editor;

/** Whether closing a buffer prompts on unsaved changes or discards them. */
enum CloseMode
{
    case Guarded;
    case Force;
}
