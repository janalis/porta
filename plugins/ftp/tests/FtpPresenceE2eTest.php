<?php

declare(strict_types=1);

namespace Porta\FtpPlugin\Tests;

use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteSpec;
use Porta\FtpPlugin\FtpTransport;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\DummyServer;
use Porta\Tests\Support\PresencePeer;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\ShowsPresenceInTheIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;

/**
 * The whole way, over FTP: the IDE opens a project on a real FTP server
 * through the bundled ftp plugin and the real curl, and shows who else is at
 * work there — and what a server that goes away does to them.
 *
 * Large: the IDE's looks and Ada's are real curl runs against the server.
 */
#[Large]
final class FtpPresenceE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPortaHome;
    use ShowsPresenceInTheIde;
    use UsesBundledPlugins;

    public function testTheIdeShowsWhoElseIsOnTheServer(): void
    {
        $root = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n\$b = 2;\n"]);
        $server = DummyServer::ftp($root->dir);
        $spec = new RemoteSpec('ftp', '127.0.0.1', '/', null, $server->port);
        $ada = new PresencePeer('Ada', $spec, static fn (): FtpTransport => new FtpTransport($spec, null));

        $this->assertTheIdeShowsWhoElseIsThere($server, $spec->url(), ['enabledPlugins' => ['ftp']], $ada);
    }
}
