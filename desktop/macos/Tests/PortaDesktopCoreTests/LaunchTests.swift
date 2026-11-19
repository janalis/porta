import XCTest
@testable import PortaDesktopCore

final class LaunchTests: XCTestCase {
    private let token = String(repeating: "a", count: 32)

    private func plan(project: String? = "/work/shop", inherited: [String: String] = [:]) -> LaunchPlan {
        LaunchPlan(runtime: "/App/runtime", project: project, inherited: inherited, token: token, home: "/Users/me")
    }

    func testPortaRunsOnTheBundledPhpWithoutTheUsersIni() {
        XCTAssertEqual(plan().executable, "/App/runtime/php")
        XCTAssertEqual(plan().arguments, ["-n", "-d", "memory_limit=-1", "-d", "display_errors=0", "/App/runtime/porta.phar", "/work/shop"])
        XCTAssertEqual(plan().workingDirectory, "/work/shop")
    }

    func testAnIconClickNamesNoProjectAndStartsAtHome() {
        XCTAssertEqual(plan(project: nil).arguments.last, "/App/runtime/porta.phar")
        XCTAssertEqual(plan(project: nil).workingDirectory, "/Users/me")
    }

    func testTheEnvironmentSaysDesktopAndDropsWhatWouldMisleadPorta() {
        let environment = plan(inherited: ["PATH": "/bin", "PHPRC": "/x", "PHP_INI_SCAN_DIR": "/y", "DYLD_INSERT_LIBRARIES": "/z", "LD_PRELOAD": "/w", "TMUX": "1", "TERM": "dumb", "WT_SESSION": "1"]).environment

        XCTAssertEqual(environment, ["COLORTERM=truecolor", "PATH=/bin", "PORTA_DESKTOP=1", "PORTA_DESKTOP_MENU=system", "PORTA_DESKTOP_TOKEN=\(token)", "TERM=xterm-256color"])
    }

    func testTokensAreLongEnoughAndNeverRepeat() {
        let first = LaunchPlan.newToken()

        XCTAssertNotNil(first.range(of: "^[0-9a-f]{32}$", options: .regularExpression))
        XCTAssertNotEqual(first, LaunchPlan.newToken())
    }

    func testAFileBelongsToTheProjectThatHoldsIt() {
        XCTAssertTrue(ProjectKey.contains("/tmp", "/tmp/a/b.php"))
        XCTAssertTrue(ProjectKey.contains("/tmp", "/tmp"))
        XCTAssertFalse(ProjectKey.contains("/tmp/shop", "/tmp/shopping/a.php"), "a prefix of the name is not the folder")
    }

    func testTwoSpellingsOfAFolderAreOneProject() {
        XCTAssertEqual(ProjectKey.of("/tmp/../tmp/"), ProjectKey.of("/tmp"))
    }
}
