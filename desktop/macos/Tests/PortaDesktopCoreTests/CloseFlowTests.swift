import XCTest
@testable import PortaDesktopCore

final class CloseFlowTests: XCTestCase {
    private func ready() -> CloseFlow {
        var flow = CloseFlow()
        _ = flow.received(.ready(root: "/p"))
        return flow
    }

    func testBeforeReadyTheWindowClosesWithoutAsking() {
        var flow = CloseFlow()
        XCTAssertEqual(flow.closeRequested(), .closeNow)
    }

    func testOnceReadyPortaIsAskedOnlyOnce() {
        var flow = ready()
        XCTAssertEqual(flow.closeRequested(), .askPorta)
        XCTAssertEqual(flow.closeRequested(), .wait)
    }

    func testUnsavedWorkKeepsTheWindowAndTheNextCloseAsksAgain() {
        var flow = ready()
        _ = flow.closeRequested()
        XCTAssertEqual(flow.received(.accepted), .stopWaiting)
        XCTAssertEqual(flow.closeRequested(), .askPorta)
    }

    func testClosingIsFinal() {
        var flow = ready()
        _ = flow.closeRequested()
        XCTAssertEqual(flow.received(.closing), .stopWaiting)
        XCTAssertEqual(flow.closeRequested(), .wait)
        XCTAssertEqual(flow.answerTimedOut(), .wait)
    }

    func testAFinishedSessionTakesTheWindowWithIt() {
        XCTAssertTrue(ready().closesWhenPortaExits(status: 0))
        XCTAssertTrue(ready().closesWhenPortaExits(status: nil), "the session had reached the IDE")
    }

    func testAFailureStaysOnScreen() {
        XCTAssertFalse(ready().closesWhenPortaExits(status: 1))
        XCTAssertFalse(CloseFlow().closesWhenPortaExits(status: nil), "it never got as far as the IDE")
        XCTAssertFalse(CloseFlow().closesWhenPortaExits(status: 255))
    }

    func testSilenceOffersAForceQuitOnce() {
        var flow = ready()
        _ = flow.closeRequested()
        XCTAssertEqual(flow.answerTimedOut(), .offerForceQuit)
        XCTAssertEqual(flow.answerTimedOut(), .wait)
    }
}
