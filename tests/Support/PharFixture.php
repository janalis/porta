<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * A real phar to read paths through, because `realpath()` answers false for
 * every `phar://` path and Porta ships as one. Built once (reading a phar
 * needs no ini change; WRITING one needs `phar.readonly=0`, which a test
 * cannot set) and committed:
 *
 *     docs/README.md               links guide.md, ../plugins/demo/docs/README.md, ../outside.md
 *     docs/guide.md                links ./README.md
 *     outside.md                   outside every docs tree
 *     plugins/demo/porta.php
 *     plugins/demo/docs/README.md  links ../../../docs/README.md
 *
 * Rebuild with `php -d phar.readonly=0`, `new Phar(...)` and
 * `setStub('<?php __HALT_COMPILER();')`.
 */
final class PharFixture
{
    /** `phar:///…/porta.phar` — append `/docs/README.md` and the like. */
    public static function root(): string
    {
        return 'phar://'.(string) \realpath(__DIR__.'/fixtures/phar/porta.phar');
    }
}
