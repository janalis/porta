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
        // The same arguments, the display line swapped for one that returns what it was given: a body
        // that would break a script it were spliced into reaches it whole.
        var arguments = ScriptedNotification.arguments(title: "Porta", body: "a \"quoted\" \\ run")
        arguments[3] = "return (item 1 of argv) & \"|\" & (item 2 of argv)"
        let process = Process()
        process.executableURL = URL(fileURLWithPath: ScriptedNotification.executable)
        process.arguments = arguments
        let output = Pipe()
        process.standardOutput = output
        try process.run()
        process.waitUntilExit()

        XCTAssertEqual(process.terminationStatus, 0)
        XCTAssertEqual(String(decoding: output.fileHandleForReading.readDataToEndOfFile(), as: UTF8.self), "Porta|a \"quoted\" \\ run\n")
    }
}
