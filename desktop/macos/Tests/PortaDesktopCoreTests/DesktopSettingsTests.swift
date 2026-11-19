import Foundation
import XCTest
@testable import PortaDesktopCore

final class DesktopSettingsTests: XCTestCase {
    func testTheDocumentedExampleReads() throws {
        let root = URL(fileURLWithPath: #filePath).deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent()
        let settings = DesktopSettings.parse(try Data(contentsOf: root.appendingPathComponent("contract/desktop-settings.json")))

        XCTAssertEqual(settings, DesktopSettings(fontFamily: "JetBrains Mono", fontSize: 14))
    }

    func testWhatCannotBeUsedReadsAsNotSet() {
        XCTAssertEqual(DesktopSettings.parse(nil), DesktopSettings())
        XCTAssertEqual(DesktopSettings.parse(Data("not json".utf8)), DesktopSettings())
        XCTAssertEqual(DesktopSettings.parse(Data(#"{"fontFamily":"","fontSize":500}"#.utf8)), DesktopSettings())
        XCTAssertEqual(DesktopSettings.parse(Data(#"{"fontFamily":3,"fontSize":"big"}"#.utf8)), DesktopSettings())
    }

    func testTheFileFollowsPortaHome() {
        XCTAssertEqual(DesktopSettings.path(environment: ["PORTA_HOME": "/x"], home: "/Users/me"), "/x/.porta/desktop.json")
        XCTAssertEqual(DesktopSettings.path(environment: [:], home: "/Users/me"), "/Users/me/.porta/desktop.json")
    }

    private func choice() throws -> [String: Any] {
        let root = URL(fileURLWithPath: #filePath).deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent()
        return try XCTUnwrap(JSONSerialization.jsonObject(with: Data(contentsOf: root.appendingPathComponent("contract/font-choice.json"))) as? [String: Any])
    }

    func testAChosenSizeIsWrittenAsTheContractSays() throws {
        let sizes = try XCTUnwrap(try choice()["sizes"] as? [[String]])
        for pair in sizes {
            XCTAssertEqual(DesktopSettings.writtenSize(try XCTUnwrap(Double(pair[0]))), Double(pair[1]), "\(pair[0]) is written as \(pair[1])")
        }
    }

    func testAChosenFontIsWrittenOverTheFileKeepingTheRest() throws {
        let existing = Data(try XCTUnwrap(try choice()["existing"] as? String).utf8)

        let written = DesktopSettings.written(existing, fontFamily: "JetBrains Mono", fontSize: 14.6)

        XCTAssertEqual(DesktopSettings.parse(written), DesktopSettings(fontFamily: "JetBrains Mono", fontSize: 15))
        let object = try XCTUnwrap(try JSONSerialization.jsonObject(with: written) as? [String: Any])
        let other = try XCTUnwrap(object["other"] as? [Any])
        XCTAssertEqual(other.first as? Int, 1, "a key the window does not know is kept")
        XCTAssertEqual((other.last as? [String: Any])?["deep"] as? Bool, true, "with all it holds")
    }

    func testAMissingOrBrokenFileIsReplacedWithTheChosenFont() {
        for existing in [nil, Data("not json".utf8), Data("[1]".utf8)] {
            XCTAssertEqual(DesktopSettings.parse(DesktopSettings.written(existing, fontFamily: "Menlo", fontSize: 13)), DesktopSettings(fontFamily: "Menlo", fontSize: 13))
        }
    }
}
