<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * A built-in search scope offered in the Find-in-Files panel's Alt+S selector:
 * the whole project, the current file's directory, just the current file, every
 * open file, or the current file's package (Module). The concrete grep paths for
 * each are resolved by the coordinator from the live editor context
 * ({@see \Porta\Ide}); this enum is only the label. The selector's cycle order
 * (built-ins then saved scopes) lives in {@see \Porta\Search\ProjectSearchController}.
 */
enum ScopeKind: string
{
    case Project = 'project';
    case Directory = 'directory';
    case CurrentFile = 'file';
    case OpenFiles = 'open';
    case Module = 'module';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Whole Project',
            self::Directory => 'Directory',
            self::CurrentFile => 'Current File',
            self::OpenFiles => 'Open Files',
            self::Module => 'Module',
        };
    }
}
