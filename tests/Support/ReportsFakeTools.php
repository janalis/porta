<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Where the fake mermaid tools (`fake-mmdc`, `fake-mermaid-browser`, the
 * fixture browser) write what they were asked, through
 * `PORTA_FAKE_REPORT_DIR`: one directory per test, read after the tool has
 * answered. Not their working directory — a render runs them in a directory
 * of its own and removes it when it is done, and never in the project,
 * whose `.puppeteerrc.cjs` the real tools would run.
 */
trait ReportsFakeTools
{
    private TempProject $fakeReports;

    #[Before]
    protected function reportFakeTools(): void
    {
        $this->fakeReports = new TempProject();
        \putenv('PORTA_FAKE_REPORT_DIR='.$this->fakeReports->dir);
    }

    #[After]
    protected function stopReportingFakeTools(): void
    {
        \putenv('PORTA_FAKE_REPORT_DIR');
    }
}
