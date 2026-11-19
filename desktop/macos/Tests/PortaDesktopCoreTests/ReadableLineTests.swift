import XCTest
@testable import PortaDesktopCore

final class ReadableLineTests: XCTestCase {
    func testDrawingIsBlankAndEveryColumnKeepsItsPlace() {
        let row = "▀▀▄▄ shop/ │ src › a.php ●✖ ──"

        let readable = ReadableLine.of(row)

        XCTAssertEqual(readable, "     shop/   src › a.php ●✖")
        XCTAssertEqual((readable as NSString).range(of: "src").location, (row as NSString).range(of: "src").location, "a column on screen is the same offset in the text")
    }

    func testARowOfDrawingAloneIsEmpty() {
        XCTAssertEqual(ReadableLine.of("▀▀▀▀────▄▄▄▄"), "")
    }
}
