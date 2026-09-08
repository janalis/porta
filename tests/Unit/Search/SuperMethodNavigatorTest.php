<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\FileIndex;
use Porta\Search\SuperMethodNavigator;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\TempProject;

/**
 * Go to Super Method, resolved from the project's own files: no language
 * server answers this request, so every hop — parent class, interface, trait,
 * grandparent — is ours to find and ours to get wrong.
 */
final class SuperMethodNavigatorTest extends TestCase
{
    private TempProject $project;

    private function navigator(): SuperMethodNavigator
    {
        $this->project = new TempProject([
            'src/Runs.php' => "<?php\nnamespace App;\ninterface Runs\n{\n    public function run(): void;\n}\n",
            'src/Logs.php' => "<?php\nnamespace App;\ntrait Logs\n{\n    public function log(string \$m): void\n    {\n    }\n}\n",
            'src/Top.php' => "<?php\nnamespace App;\nabstract class Top\n{\n    public function deep(): void\n    {\n    }\n}\n",
            'src/Mid.php' => "<?php\nnamespace App;\nabstract class Mid extends Top\n{\n}\n",
            'vendor/acme/src/Vendored.php' => "<?php\nnamespace Acme;\nclass Vendored\n{\n    public function vendored(): void\n    {\n    }\n}\n",
        ]);

        return new SuperMethodNavigator(new FileIndex($this->project->dir), new ProjectPaths($this->project->dir));
    }

    /** The subject: one class overriding something from each kind of ancestor. */
    private function subject(): string
    {
        return (
            "<?php\nnamespace App;\nfinal class Impl extends Mid implements Runs\n{\n    use Logs;\n\n"
            ."    public function run(): void\n    {\n    }\n\n"
            ."    public function log(string \$m): void\n    {\n    }\n\n"
            ."    public function deep(): void\n    {\n    }\n\n"
            ."    public function own(): void\n    {\n    }\n}\n"
        );
    }

    public function testItFindsTheDeclarationInAnInterface(): void
    {
        // Bodyless, which is why the scope scanner alone cannot answer this:
        // an interface method opens no brace and so is no scope at all.
        $hit = $this->navigator()->locate($this->subject(), caretLine: 6);

        $this->assertSame('src/Runs.php', $hit['path'] ?? null);
        $this->assertSame('Runs::run', $hit['label'] ?? null);
        $this->assertSame(4, $hit['line'] ?? null, '0-based, as the editor counts');
    }

    public function testItFindsTheDeclarationInAUsedTrait(): void
    {
        $hit = $this->navigator()->locate($this->subject(), caretLine: 10);

        $this->assertSame('Logs::log', $hit['label'] ?? null);
    }

    public function testItClimbsPastAParentThatDoesNotDeclareIt(): void
    {
        // Mid extends Top and declares nothing: the answer is the grandparent's,
        // which is what an override actually replaces.
        $hit = $this->navigator()->locate($this->subject(), caretLine: 14);

        $this->assertSame('Top::deep', $hit['label'] ?? null);
    }

    public function testAMethodThatOverridesNothingHasNoSuper(): void
    {
        $this->assertNull($this->navigator()->locate($this->subject(), caretLine: 18));
    }

    public function testACaretOutsideAnyMethodHasNoSuper(): void
    {
        // On the class line itself: there is a type but no method, and
        // answering with the parent CLASS would be a different feature.
        $this->assertNull($this->navigator()->locate($this->subject(), caretLine: 2));
    }

    public function testVendoredParentsFollowTheSearchScopeSetting(): void
    {
        // Not a rule of this feature's own: the shared index decides, and the
        // settings page owns that switch. Off, a vendored parent is out of
        // reach; on, it is exactly the framework class you meant.
        $files = new FileIndex($this->projectWithVendor()->dir);
        $navigator = new SuperMethodNavigator($files, new ProjectPaths($this->project->dir));
        $source = "<?php\nnamespace App;\nfinal class Local extends Vendored\n{\n    public function vendored(): void\n    {\n    }\n}\n";

        $this->assertNull($navigator->locate($source, caretLine: 4), 'out of scope by default');

        $files->setIncludeVendor(true);

        $this->assertSame('Vendored::vendored', $navigator->locate($source, caretLine: 4)['label'] ?? null);
    }

    private function projectWithVendor(): TempProject
    {
        $this->navigator(); // builds the fixture, vendored file included

        return $this->project;
    }

    public function testAFullyQualifiedParentResolvesByItsLastSegment(): void
    {
        // `\App\Mid`, `Base\Mid` and an aliased `Mid` all name the same file,
        // which is the whole reason the short name is enough.
        $source = "<?php\nnamespace App;\nfinal class Impl extends \\App\\Mid\n{\n    public function deep(): void\n    {\n    }\n}\n";

        $this->assertSame('Top::deep', $this->navigator()->locate($source, caretLine: 4)['label'] ?? null);
    }

    public function testACycleInTheHierarchyStopsInsteadOfSpinning(): void
    {
        // A mistyped hierarchy is a thing people have on screen while editing;
        // it must cost a keystroke, not a hang.
        $navigator = $this->navigator();
        $this->project->write('src/A.php', "<?php\nnamespace App;\nclass A extends B\n{\n}\n");
        $this->project->write('src/B.php', "<?php\nnamespace App;\nclass B extends A\n{\n}\n");
        $source = "<?php\nnamespace App;\nfinal class C extends A\n{\n    public function ghost(): void\n    {\n    }\n}\n";

        $this->assertNull($navigator->locate($source, caretLine: 4));
    }

    public function testAClosureInAParentIsNotMistakenForTheMethod(): void
    {
        // `function ()` with no name is a closure; asking its name must answer
        // nothing rather than swallow the next token as one.
        $navigator = $this->navigator();
        $this->project->write(
            'src/Closures.php',
            "<?php\nnamespace App;\nclass Closures\n{\n    public function build(): callable\n    {\n        return function (): int {\n            return 1;\n        };\n    }\n}\n",
        );
        $source = "<?php\nnamespace App;\nfinal class Uses extends Closures\n{\n    public function build(): callable\n    {\n    }\n}\n";

        $this->assertSame('Closures::build', $navigator->locate($source, caretLine: 4)['label'] ?? null);
    }

    public function testAParentThatVanishedBetweenTheWalkAndTheReadIsSkipped(): void
    {
        // The index is a snapshot; a file deleted after it was taken is a
        // candidate that cannot be read, and that must be a miss rather than
        // an error on a keystroke.
        $navigator = $this->navigator();
        $source = "<?php\nnamespace App;\nfinal class Impl extends Mid\n{\n    public function deep(): void\n    {\n    }\n}\n";
        $this->assertNotNull($navigator->locate($source, caretLine: 4), 'found while the files are there');

        unlink($this->project->path('src/Mid.php'));
        unlink($this->project->path('src/Top.php'));

        $this->assertNull($navigator->locate($source, caretLine: 4));
    }

    public function testATruncatedParentFileIsAMissRatherThanACrash(): void
    {
        // A file caught mid-write ends wherever it ends: the last token can be
        // the `function` keyword itself, with no name after it to read.
        $navigator = $this->navigator();
        $this->project->write('src/Half.php', "<?php\nnamespace App;\nclass Half\n{\n    public function");
        $source = "<?php\nnamespace App;\nfinal class Impl extends Half\n{\n    public function anything(): void\n    {\n    }\n}\n";

        $this->assertNull($navigator->locate($source, caretLine: 4));
    }

    public function testTwoClassesInOneFileFollowTheirOwnParents(): void
    {
        // Rare in a PSR-4 tree and everywhere in fixtures: the parents that
        // matter are the ones on the type the caret is INSIDE, not every type
        // the file happens to declare.
        $navigator = $this->navigator();
        $this->project->write('src/Other.php', "<?php\nnamespace App;\nabstract class Other\n{\n    public function deep(): void\n    {\n    }\n}\n");
        $source = "<?php\nnamespace App;\nfinal class First extends Other\n{\n}\n\nfinal class Second extends Mid\n{\n    public function deep(): void\n    {\n    }\n}\n";

        // The caret is in Second, whose chain is Mid → Top.
        $this->assertSame('Top::deep', $navigator->locate($source, caretLine: 8)['label'] ?? null);
    }
}
