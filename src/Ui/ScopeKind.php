<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * The search scope shown in the Find-in-Files panel and cycled with Alt+S: the
 * whole project, the current file's directory, just the current file, or every
 * open file. The concrete grep paths for each are resolved by the coordinator
 * from the live editor context ({@see \Porta\Ide}); this enum is only the label
 * + cycle order.
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

    public function next(): self
    {
        return match ($this) {
            self::Project => self::Directory,
            self::Directory => self::CurrentFile,
            self::CurrentFile => self::OpenFiles,
            self::OpenFiles => self::Module,
            self::Module => self::Project,
        };
    }
}
