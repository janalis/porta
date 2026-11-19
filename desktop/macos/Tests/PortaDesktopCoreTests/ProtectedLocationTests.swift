import XCTest
@testable import PortaDesktopCore

final class ProtectedLocationTests: XCTestCase {
    func testTheFoldersMacOSGuardsAreNamedAsSystemSettingsNamesThem() {
        XCTAssertEqual(ProtectedLocation.of("/Users/ana/Documents/shop", home: "/Users/ana"), "Documents")
        XCTAssertEqual(ProtectedLocation.of("/Users/ana/Desktop", home: "/Users/ana"), "Desktop")
        XCTAssertEqual(ProtectedLocation.of("/Users/ana/Downloads/x/y", home: "/Users/ana"), "Downloads")
        XCTAssertEqual(ProtectedLocation.of("/Users/ana/Library/Mobile Documents/com~apple~CloudDocs/shop", home: "/Users/ana"), "iCloud Drive")
        XCTAssertEqual(ProtectedLocation.of("/Volumes/USB/shop", home: "/Users/ana"), "Removable Volumes")
    }

    func testAnywhereElseNeedsNoPermission() {
        XCTAssertNil(ProtectedLocation.of("/Users/ana/Sites/shop", home: "/Users/ana"))
        XCTAssertNil(ProtectedLocation.of("/Users/ana/DocumentsOld/shop", home: "/Users/ana"), "a prefix of a name is not the folder")
        XCTAssertNil(ProtectedLocation.of("/Volumes", home: "/Users/ana"))
    }
}
