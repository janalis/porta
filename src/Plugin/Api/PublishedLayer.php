<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/** A kind of thing a plugin puts on screen through {@see PluginApi}, for {@see PublishedLayers} to take back. */
enum PublishedLayer
{
    case Diagnostics;
    case ChangeMarks;
    case CommentMarks;
    case Coverage;
    case FileStatuses;
    case ChangeGutter;
    case LineAnnotations;
    case LineBlocks;
}
