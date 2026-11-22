<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CodeScopeKind;
use Symfony\Component\Process\Process;

/**
 * PHP 8.6 deprecates an enum constant called `namespace`, case-blind: a case
 * of that name raises the deprecation when the enum is first loaded, which
 * is the first PHP file with a namespace the editor scans. Loaded here
 * already, so a PHP of its own loads it fresh.
 */
final class CodeScopeKindTest extends TestCase
{
    public function testLoadingTheKindsRaisesNothing(): void
    {
        $load = new Process([
            \PHP_BINARY,
            '-d',
            'error_reporting=-1',
            '-d',
            'display_errors=stderr',
            '-r',
            \sprintf('require %s; %s::cases();', \var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', return: true), CodeScopeKind::class),
        ]);
        $load->mustRun();

        $this->assertSame('', $load->getErrorOutput().$load->getOutput());
    }
}
