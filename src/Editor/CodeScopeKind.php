<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * What a structural scope is — the `kind` of one entry in the scopes shape that
 * {@see BreadcrumbScanner::scan()} and {@see \Porta\Lsp\LspMapper::symbolScopes()}
 * both produce, and that breadcrumbs, sticky headers, member navigation and
 * reference copying read.
 *
 * Pure: the shape lives in memory for one document version and is never
 * written, sent or shown by name.
 */
enum CodeScopeKind
{
    case Namespace;
    /** A class, interface, trait or enum (LSP: class, enum, interface, struct). */
    case Type;
    /** A function or method (LSP: method, constructor, function). */
    case Function;
}
