import AppKit
import XCTest
@testable import PortaDesktopCore

final class DropViewTests: XCTestCase {
    private var pasteboard: NSPasteboard!

    override func setUp() {
        pasteboard = NSPasteboard(name: NSPasteboard.Name("porta-drop-\(UUID().uuidString)"))
        pasteboard.clearContents()
    }

    override func tearDown() {
        pasteboard.releaseGlobally()
    }

    func testDroppedFilesAndFoldersAreTheirPaths() {
        pasteboard.writeObjects([URL(fileURLWithPath: "/work/shop/src/a.php") as NSURL, URL(fileURLWithPath: "/work/other", isDirectory: true) as NSURL])

        XCTAssertEqual(DropView.paths(on: pasteboard), ["/work/shop/src/a.php", "/work/other"])
    }

    func testADraggedLinkOrTextIsNothingToOpen() {
        pasteboard.writeObjects([URL(string: "https://example.org/a.php")! as NSURL, "/work/shop/src/a.php" as NSString])

        XCTAssertEqual(DropView.paths(on: pasteboard), [])
    }

    func testTheTerminalFillsTheViewThatHoldsIt() {
        let terminal = NSView(frame: NSRect(x: 0, y: 0, width: 10, height: 10))
        let holder = DropView(holding: terminal)

        holder.setFrameSize(NSSize(width: 300, height: 200))

        XCTAssertEqual(terminal.frame, holder.bounds)
        XCTAssertTrue(holder.registeredDraggedTypes.contains(.fileURL))
    }
}
