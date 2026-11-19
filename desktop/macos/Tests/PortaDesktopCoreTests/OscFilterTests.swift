import XCTest
@testable import PortaDesktopCore

final class OscFilterTests: XCTestCase {
    private let sequence = "\u{1b}]7777;porta-desktop;t;closing\u{1b}\\"

    private func run(_ chunks: [String]) -> (String, [String]) {
        var filter = OscFilter()
        var output: [UInt8] = []
        var payloads: [String] = []
        for chunk in chunks {
            let result = filter.consume(Array(chunk.utf8)[...])
            output += result.output
            payloads += result.payloads
        }
        return (String(decoding: output, as: UTF8.self), payloads)
    }

    func testThePrivateSequenceIsTakenOutOfTheOutput() {
        let (output, payloads) = run(["before" + sequence + "after"])

        XCTAssertEqual(output, "beforeafter")
        XCTAssertEqual(payloads, ["porta-desktop;t;closing"])
    }

    func testASplitAtEveryByteChangesNothing() {
        let whole = "a" + sequence + "b"
        for cut in 1..<whole.utf8.count {
            let bytes = Array(whole.utf8)
            let (output, payloads) = run([String(decoding: bytes[..<cut], as: UTF8.self), String(decoding: bytes[cut...], as: UTF8.self)])

            XCTAssertEqual(output, "ab", "cut at \(cut)")
            XCTAssertEqual(payloads, ["porta-desktop;t;closing"], "cut at \(cut)")
        }
    }

    func testOtherEscapesPassUntouched() {
        let others = "\u{1b}[31mred\u{1b}]0;title\u{07}\u{1b}]777;x\u{07}\u{1b}\u{1b}]52;c;aGk=\u{07}"

        XCTAssertEqual(run([others]).0, others)
        XCTAssertEqual(run([others]).1, [])
    }

    func testAStringSequenceWrittenIntoThePayloadGoesWholeToTheTerminal() {
        let image = "\u{1b}_Ga=T;AAAA\u{1b}\\"
        let title = "\u{1b}]0;title\u{07}"
        let (output, payloads) = run(["\u{1b}]7777;ab" + image + "cd" + title + "ef\u{1b}\\"])

        XCTAssertEqual(payloads, ["abcdef"])
        XCTAssertEqual(output, image + title)
    }

    func testABellEndsThePayloadToo() {
        XCTAssertEqual(run(["\u{1b}]7777;x\u{07}"]).1, ["x"])
    }

    func testASequenceWrittenIntoThePayloadGoesToTheTerminal() {
        let (output, payloads) = run(["x\u{1b}]7777;ab", "c\u{1b}[>7", "ude\u{1b}Mf\u{1b}\\y"])

        XCTAssertEqual(payloads, ["abcdef"])
        XCTAssertEqual(output, "x\u{1b}[>7u\u{1b}My")
    }

    func testARunawayPayloadIsHandedBackToTheTerminal() {
        let long = String(repeating: "x", count: 4 * 1024 * 1024 + 1)

        XCTAssertEqual(run(["\u{1b}]7777;" + long]).0, "\u{1b}]7777;" + long)
    }
}
