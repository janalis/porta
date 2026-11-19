/// What the close button does, which depends on how far Porta has got.
public struct CloseFlow: Equatable {
    public enum Step: Equatable {
        /// Close the window and end Porta: nothing can be unsaved yet.
        case closeNow
        /// Ask Porta, and wait a few seconds for an answer.
        case askPorta
        /// Porta is already on its way out, or has answered: nothing to do.
        case wait
        /// Stop waiting for an answer.
        case stopWaiting
        /// Porta did not answer: the user decides.
        case offerForceQuit
    }

    private enum State: Equatable {
        case starting, ready, asking, closing
    }

    private var state: State = .starting
    private var wasReady = false

    public init() {}

    public mutating func closeRequested() -> Step {
        switch state {
        case .starting:
            return .closeNow
        case .ready:
            state = .asking
            return .askPorta
        case .asking, .closing:
            return .wait
        }
    }

    public mutating func received(_ message: DesktopMessage) -> Step {
        switch message {
        case .ready:
            state = .ready
            wasReady = true
            return .wait
        case .accepted:
            // Unsaved work: Porta has said why, and the window stays.
            state = .ready
            return .stopWaiting
        case .closing:
            state = .closing
            return .stopWaiting
        case .menu:
            return .wait
        }
    }

    /// Whether the window goes with Porta. A session that reached the IDE can end on a signal and
    /// still be finished. A failure to start stays on screen: what it printed is the only explanation.
    public func closesWhenPortaExits(status: Int32?) -> Bool {
        status == 0 || (wasReady && status == nil)
    }

    public mutating func answerTimedOut() -> Step {
        guard state == .asking else {
            return .wait
        }
        state = .ready
        return .offerForceQuit
    }
}
