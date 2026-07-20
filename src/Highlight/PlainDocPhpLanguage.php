<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Tempest\Highlight\Languages\Php\Injections\PhpDocCommentInjection;
use Tempest\Highlight\Languages\Php\PhpLanguage;

/**
 * PHP highlighting with docblocks left as plain comments.
 *
 * Tempest's {@see PhpDocCommentInjection} re-tokenizes a `/** *\/` block into
 * tags, types and variables using the *same* token types as ordinary code
 * (TYPE/VARIABLE/VALUE). A terminal theme colors by token type and its markup
 * is cached per type, so `after()` cannot tell a docblock type from a code
 * type: it resets the foreground to default, and the docblock's prose and
 * continuation `*` markers lose the enclosing comment gray — rendering white
 * mid-comment. Dropping the injection keeps the whole docblock a single comment
 * token, so it reads uniformly as a comment (the multiline-comment pattern
 * still colors it). Registered over the built-in `php` language by name.
 */
final class PlainDocPhpLanguage extends PhpLanguage
{
    public function getInjections(): array
    {
        return array_values(array_filter(
            parent::getInjections(),
            static fn (object $injection): bool => !$injection instanceof PhpDocCommentInjection,
        ));
    }
}
