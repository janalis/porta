import XCTest
@testable import PortaDesktopCore

final class FontPickTests: XCTestCase {
    func testAClickBesideTheListChangesNothing() {
        XCTAssertEqual(FontPick.judge(currentName: ".AppleSystemUIFontMonospaced-Regular", currentSize: 13, chosenName: ".AppleSystemUIFontMonospaced-Regular", chosenFamily: ".AppleSystemUIFontMonospaced", chosenSize: 13, fixedPitch: true), .unchanged)
    }

    func testTheSystemFontsPrivateNameIsNeverWritten() {
        XCTAssertEqual(FontPick.judge(currentName: "Menlo-Regular", currentSize: 13, chosenName: ".AppleSystemUIFontMonospaced-Regular", chosenFamily: ".AppleSystemUIFontMonospaced", chosenSize: 13, fixedPitch: true), .systemDefault)
    }

    func testAProportionalFontIsRefusedAndAMonospacedOneChosen() {
        XCTAssertEqual(FontPick.judge(currentName: "Menlo-Regular", currentSize: 13, chosenName: "AmericanTypewriter", chosenFamily: "American Typewriter", chosenSize: 13, fixedPitch: false), .refused)
        XCTAssertEqual(FontPick.judge(currentName: "Menlo-Regular", currentSize: 13, chosenName: "AndaleMono", chosenFamily: "Andale Mono", chosenSize: 13, fixedPitch: true), .chosen(family: "Andale Mono", size: 13))
        XCTAssertEqual(FontPick.judge(currentName: "Menlo-Regular", currentSize: 13, chosenName: "Menlo-Regular", chosenFamily: "Menlo", chosenSize: 15, fixedPitch: true), .chosen(family: "Menlo", size: 15), "a new size alone is a choice")
    }
}
