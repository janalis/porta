import XCTest
@testable import PortaDesktopCore

final class ReadableLineTests: XCTestCase {
    func testDrawingIsBlankAndEveryColumnKeepsItsPlace() {
        let row = "▀▀▄▄ shop/ │ src › a.php ●✖ ──"

        let readable = ReadableLine.of(row)

        XCTAssertEqual(readable, "     shop/   src › a.php ●✖")
        XCTAssertEqual((readable as NSString).range(of: "src").location, (row as NSString).range(of: "src").location, "drawing keeps the row's own offsets")
    }

    func testARowOfDrawingAloneIsEmpty() {
        XCTAssertEqual(ReadableLine.of("▀▀▀▀────▄▄▄▄"), "")
    }

    func testAColumnAfterWideCharactersIsTheirTextsLength() {
        // 日本語 fills six columns, three characters; the cursor sits after "$ " at column 9.
        let text = "日本語 $ x"
        let widths = [2, 0, 2, 0, 2, 0, 1, 1, 1, 1]

        XCTAssertEqual(ReadableLine.offset(ofColumn: 9, widths: widths, in: text), 6)
        XCTAssertEqual(ReadableLine.offset(ofColumn: 0, widths: widths, in: text), 0)
        XCTAssertEqual(ReadableLine.offset(ofColumn: 40, widths: widths, in: text), (text as NSString).length, "past the row: its end")
    }

    func testAnEmojiIsTwoUnitsInOneCellPair() {
        XCTAssertEqual(ReadableLine.offset(ofColumn: 3, widths: [2, 0, 1], in: "😀a"), 3)
    }
}
