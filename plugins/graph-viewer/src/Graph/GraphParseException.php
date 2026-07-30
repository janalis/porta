<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/** Raised when `dot -Tjson0` output (or a hand-fed document) cannot be mapped to a {@see GraphLayout}. */
final class GraphParseException extends \RuntimeException {}
