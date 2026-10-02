<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * Every TempProject is trusted by the suite (`PORTA_TRUSTED_FOLDERS`, set in
 * bootstrap.php to the temp dir they live in). A test of what an UNTRUSTED
 * project may not do calls {@see untrustProjects()} — and gets PORTA_HOME a
 * home of its own, so no answer is read from, or written to, the committed
 * fixture home. Restored after every test, the {@see PreservesPortaHome} way.
 */
trait UntrustsProjects
{
    private TempProject $untrustingHome;

    /** Call it first in a test: from here on, no TempProject is trusted. */
    protected function untrustProjects(): void
    {
        $this->untrustingHome = new TempProject();
        \putenv('PORTA_TRUSTED_FOLDERS');
        \putenv('PORTA_HOME='.$this->untrustingHome->dir);
    }

    #[After]
    protected function trustProjectsAgain(): void
    {
        \putenv('PORTA_TRUSTED_FOLDERS='.(string) \PORTA_TEST_TRUSTED_FOLDERS);
        $home = (string) \PORTA_TEST_PRISTINE_PORTA_HOME;
        \putenv('' !== $home ? 'PORTA_HOME='.$home : 'PORTA_HOME');
    }
}
