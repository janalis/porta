import XCTest
@testable import PortaDesktopCore

final class ScriptedNotificationTests: XCTestCase {
    func testTheWordsAreArgumentsNotScript() {
        let title = "shop — Porta"
        let body = "\"; do shell script \"touch /tmp/owned\" --"
        let arguments = ScriptedNotification.arguments(title: title, body: body)

        // The script is fixed: nothing of the run's text is in it.
        let script = stride(from: 1, to: 6, by: 2).map { arguments[$0] }.joined(separator: "\n")
        XCTAssertFalse(script.contains("touch"))
        XCTAssertEqual(Array(arguments.suffix(2)), [title, body])
    }

    func testOsascriptGetsTheWordsAsTheyAre() throws {
        // A body that would break a script it were spliced into reaches the script whole.
        XCTAssertEqual(try returned(title: "Porta", body: "a \"quoted\" \\ run"), "Porta|a \"quoted\" \\ run\n")
    }

    func testATitleThatLooksLikeAnOptionIsNotScript() throws {
        // A project folder named like an option: without `--`, osascript compiles it as script.
        XCTAssertEqual(try returned(title: "-e", body: "return 42"), "-e|return 42\n")
    }

    /// The same arguments, the display line swapped for one that returns what it was given.
    private func returned(title: String, body: String) throws -> String {
        var arguments = ScriptedNotification.arguments(title: title, body: body)
        arguments[3] = "return (item 1 of argv) & \"|\" & (item 2 of argv)"
        let process = Process()
        process.executableURL = URL(fileURLWithPath: ScriptedNotification.executable)
        process.arguments = arguments
        let output = Pipe()
        process.standardOutput = output
        process.standardError = Pipe()
        try process.run()
        process.waitUntilExit()
        XCTAssertEqual(process.terminationStatus, 0)
        return String(decoding: output.fileHandleForReading.readDataToEndOfFile(), as: UTF8.self)
    }
}
