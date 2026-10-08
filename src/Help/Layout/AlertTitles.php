<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Node\AlertKind;
use Porta\Ui\Role;

/**
 * What an alert says it is, and its colour: GitHub's five kinds, each title
 * in the reader's language.
 */
final readonly class AlertTitles
{
    /** @param \Closure(string): string $translate a catalog key to its text */
    public function __construct(
        private \Closure $translate,
    ) {}

    public static function english(): self
    {
        return new self(static fn (string $key): string => \ucfirst(\substr($key, \strlen('markdown.alert.'))));
    }

    public function title(AlertKind $kind): string
    {
        return ($this->translate)('markdown.alert.'.\strtolower($kind->value));
    }

    public function role(AlertKind $kind): Role
    {
        return match ($kind) {
            AlertKind::Note, AlertKind::Tip => Role::TabAccent,
            AlertKind::Important => Role::Emphasis,
            AlertKind::Warning => Role::StatusWarning,
            AlertKind::Caution => Role::StatusError,
        };
    }
}
