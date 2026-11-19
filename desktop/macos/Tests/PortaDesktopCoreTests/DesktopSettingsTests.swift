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
}
