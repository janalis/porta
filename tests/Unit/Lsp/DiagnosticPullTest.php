<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\DiagnosticPull;

/** What is asked of a server that answers diagnostics only when asked, and what reaches the editor. */
final class DiagnosticPullTest extends TestCase
{
    /** @var list<array{method: string, params: mixed, respond: \Closure(mixed, mixed=): void}> */
    private array $requests = [];

    /** @var list<array<string, mixed>> */
    private array $published = [];

    /** @param array<array-key, mixed> $provider */
    private function pull(array $provider = []): DiagnosticPull
    {
        return new DiagnosticPull(
            $provider,
            function (string $method, mixed $params, callable $respond): void {
                $this->requests[] = ['method' => $method, 'params' => $params, 'respond' => $respond(...)];
            },
            function (array $params): void {
                $this->published[] = $params;
            },
        );
    }

    public function testAFullAnswerIsPublishedAndItsResultIdSentNextTime(): void
    {
        $pull = $this->pull(['identifier' => 'phpantom']);

        $pull->pull('file:///a.php');
        $this->assertSame('textDocument/diagnostic', $this->requests[0]['method']);
        $this->assertSame(['textDocument' => ['uri' => 'file:///a.php'], 'identifier' => 'phpantom'], $this->requests[0]['params']);
        $this->requests[0]['respond'](['kind' => 'full', 'resultId' => 'r1', 'items' => ['x' => ['message' => 'Class not found']]]);
        $this->assertSame([['uri' => 'file:///a.php', 'diagnostics' => [['message' => 'Class not found']]]], $this->published);

        $pull->pull('file:///a.php');
        $this->assertSame('r1', $this->requests[1]['params']['previousResultId'] ?? null, 'the server may answer "unchanged" against it');
        $this->requests[1]['respond'](['kind' => 'full', 'items' => []]);
        $this->assertSame(['uri' => 'file:///a.php', 'diagnostics' => []], $this->published[1], 'a full answer with none clears');

        $pull->pull('file:///a.php');
        $this->assertArrayNotHasKey('previousResultId', $this->requests[2]['params'], 'an answer with no id forgets the old one');
    }

    public function testWithoutAnIdentifierNoneIsSent(): void
    {
        $this->pull(['identifier' => 7])->pull('file:///a.php');

        $this->assertSame(['textDocument' => ['uri' => 'file:///a.php']], $this->requests[0]['params']);
    }

    public function testUnchangedErrorsAndMalformedAnswersLeaveWhatIsShown(): void
    {
        $pull = $this->pull();
        foreach ([['kind' => 'unchanged', 'resultId' => 'r'], null, 'full', ['kind' => 'full', 'items' => 'x']] as $i => $answer) {
            $pull->pull('file:///a.php');
            $this->requests[$i]['respond']($answer);
        }

        $this->assertSame([['uri' => 'file:///a.php', 'diagnostics' => []]], $this->published, 'only the full one, its items unreadable, counts');
    }

    public function testOnlyTheLatestQuestionAboutAFileIsAnswered(): void
    {
        $pull = $this->pull();
        $pull->pull('file:///a.php');
        $pull->pull('file:///b.php');
        $pull->pull('file:///a.php');

        $this->requests[0]['respond'](['kind' => 'full', 'items' => [['message' => 'stale']]]);
        $this->assertSame([], $this->published, 'about text that has since changed');
        $this->requests[1]['respond'](['kind' => 'full', 'items' => [['message' => 'b']]]);
        $this->requests[2]['respond'](['kind' => 'full', 'items' => [['message' => 'fresh']]]);
        $this->assertSame(['file:///b.php', 'file:///a.php'], \array_column($this->published, 'uri'), 'another file\'s question is its own');
    }

    public function testAClosedFileIsClearedAndALateAnswerDropped(): void
    {
        $pull = $this->pull();
        $pull->forget('file:///never-asked.php');
        $this->assertSame([], $this->published, 'nothing was shown for it');

        $pull->pull('file:///a.php');
        $pull->forget('file:///a.php');
        $this->assertSame([['uri' => 'file:///a.php', 'diagnostics' => []]], $this->published);

        $this->requests[0]['respond'](['kind' => 'full', 'items' => [['message' => 'late']]]);
        $this->assertCount(1, $this->published, 'an answer arriving after the close does not bring it back');
    }

    public function testInterFileDependenciesAreReadFromTheProvider(): void
    {
        $this->assertTrue($this->pull(['interFileDependencies' => true])->dependsOnOtherFiles());
        $this->assertFalse($this->pull(['interFileDependencies' => 1])->dependsOnOtherFiles());
        $this->assertFalse($this->pull()->dependsOnOtherFiles());
    }
}
