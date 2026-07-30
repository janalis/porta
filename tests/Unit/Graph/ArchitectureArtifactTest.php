<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use Porta\Graph\ArchitectureArtifact;

/**
 * Where the artifact lands. The generation itself (scanner, generator command,
 * failures) is driven end to end by IdeGraphViewerTest against the real plugin
 * file; this pins the output-directory setting's four readings.
 */
final class ArchitectureArtifactTest extends TestCase
{
    public function testTheDefaultIsTheProjectRoot(): void
    {
        $this->assertSame('/p/architecture.dot', new ArchitectureArtifact('/p')->path());
        $this->assertSame('/p/architecture.dot', new ArchitectureArtifact('/p', '')->path(), 'an empty setting reads as the root');
        $this->assertSame('/p/architecture.dot', new ArchitectureArtifact('/p', '   ')->path(), 'so does a blank one');
    }

    public function testARelativeDirectoryResolvesFromTheProjectRoot(): void
    {
        $this->assertSame('/p/docs/arch/architecture.dot', new ArchitectureArtifact('/p', 'docs/arch')->path());
        $this->assertSame('/p/docs/architecture.dot', new ArchitectureArtifact('/p', 'docs/')->path(), 'a trailing slash is not a doubled one');
    }

    public function testAnAbsoluteDirectoryIsTakenAsGiven(): void
    {
        $this->assertSame('/tmp/out/architecture.dot', new ArchitectureArtifact('/p', '/tmp/out')->path());
    }
}
