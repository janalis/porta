import AppKit
import PortaDesktopCore
import SwiftTerm

/// One window running one Porta.
final class ProjectWindowController: NSWindowController, NSWindowDelegate, TerminalViewDelegate, LocalProcessDelegate {
    private static let answerSeconds: TimeInterval = 4
    private static let shutdownSeconds: TimeInterval = 15
    private static let defaultFontSize: CGFloat = 13
    private static let fontSizeKey = "fontSize"

    var onClosed: (() -> Void)?
    var onCloseRefused: (() -> Void)?
    private(set) var projectKey: String?

    private let plan: LaunchPlan
    private let token: String
    private let terminal: TerminalView
    private var process: LocalProcess!
    private var filter = OscFilter()
    private var flow = CloseFlow()
    private var answerTimer: Timer?
    private var exited = false

    init(runtime: String, project: String?) {
        token = LaunchPlan.newToken()
        plan = LaunchPlan(runtime: runtime, project: project, inherited: ProcessInfo.processInfo.environment, token: token, home: NSHomeDirectory())
        projectKey = project.map(ProjectKey.of)
        terminal = TerminalView(frame: NSRect(x: 0, y: 0, width: 1100, height: 720))

        let window = NSWindow(
            contentRect: NSRect(x: 0, y: 0, width: 1100, height: 720),
            styleMask: [.titled, .closable, .miniaturizable, .resizable],
            backing: .buffered,
            defer: false
        )
        window.title = "Porta"
        window.minSize = NSSize(width: 480, height: 320)
        window.collectionBehavior = [.fullScreenPrimary]
        window.contentView = terminal
        window.setFrameAutosaveName("PortaProjectWindow")
        super.init(window: window)
        window.delegate = self
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) {
        fatalError("Porta windows are built in code")
    }

    func start() {
        // Before the font: setting one resizes the terminal, which reports to the process.
        process = LocalProcess(delegate: self, dispatchQueue: .main)
        terminal.terminalDelegate = self
        terminal.font = Self.font(size: Self.savedFontSize)
        focus()
        window?.makeFirstResponder(terminal)
        process.startProcess(executable: plan.executable, args: plan.arguments, environment: plan.environment, currentDirectory: plan.workingDirectory)
    }

    func focus() {
        window?.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)
    }

    func pasteClipboard() {
        terminal.paste(self)
    }

    /// The menus this window's Porta offers, shown while it is the key window.
    private(set) var menuBar: MenuBar?
    var onMenuChanged: ((ProjectWindowController) -> Void)?

    func chooseMenuItem(generation: String, menu: Int, item: Int) {
        send(DesktopMessage.menuChoice(token: token, generation: generation, menu: menu, item: item))
    }

    func windowDidBecomeKey(_ notification: Notification) {
        onMenuChanged?(self)
    }

    func send(_ text: String) {
        process.send(data: Array(text.utf8)[...])
    }

    /// The close button, Cmd+W and quitting the application all come through here.
    func requestClose() {
        guard !exited else {
            window?.close()
            return
        }
        switch flow.closeRequested() {
        case .closeNow:
            endPorta()
        case .askPorta:
            send(DesktopMessage.closeRequest(token: token))
            armAnswerTimer(Self.answerSeconds)
        default:
            break
        }
    }

    func windowShouldClose(_ sender: NSWindow) -> Bool {
        if exited {
            return true
        }
        requestClose()
        return false
    }

    func windowWillClose(_ notification: Notification) {
        answerTimer?.invalidate()
        onClosed?()
    }

    func zoom(by step: CGFloat) {
        let size = max(8, min(40, terminal.font.pointSize + step))
        terminal.font = Self.font(size: size)
        UserDefaults.standard.set(Double(size), forKey: Self.fontSizeKey)
    }

    func zoomReset() {
        terminal.font = Self.font(size: Self.defaultFontSize)
        UserDefaults.standard.removeObject(forKey: Self.fontSizeKey)
    }

    private static var savedFontSize: CGFloat {
        let saved = UserDefaults.standard.double(forKey: fontSizeKey)
        return saved > 0 ? CGFloat(saved) : defaultFontSize
    }

    private static func font(size: CGFloat) -> NSFont {
        NSFont.monospacedSystemFont(ofSize: size, weight: .regular)
    }

    private func received(_ payload: String) {
        guard let message = DesktopMessage.parse(payload: payload, token: token) else {
            return
        }
        if case let .ready(root) = message {
            projectKey = ProjectKey.of(root)
        }
        if case let .menu(bar) = message {
            menuBar = bar
            onMenuChanged?(self)
        }
        switch flow.received(message) {
        case .stopWaiting:
            answerTimer?.invalidate()
            onCloseRefused?()
        case .waitForExit:
            armAnswerTimer(Self.shutdownSeconds)
        default:
            break
        }
    }

    private func armAnswerTimer(_ seconds: TimeInterval) {
        answerTimer?.invalidate()
        answerTimer = Timer.scheduledTimer(withTimeInterval: seconds, repeats: false) { [weak self] _ in
            self?.answerTimedOut()
        }
    }

    private func answerTimedOut() {
        guard flow.answerTimedOut() == .offerForceQuit else {
            return
        }
        let alert = NSAlert()
        alert.messageText = "Porta is not responding"
        alert.informativeText = "Force quitting loses any unsaved changes in this window."
        alert.addButton(withTitle: "Keep Waiting")
        alert.addButton(withTitle: "Force Quit")
        if alert.runModal() == .alertSecondButtonReturn {
            endPorta()
        } else if flow.isClosing {
            armAnswerTimer(Self.shutdownSeconds)
        } else {
            onCloseRefused?()
        }
    }

    private func endPorta() {
        exited = true
        process.terminate()
        window?.close()
    }

    // MARK: LocalProcessDelegate

    func processTerminated(_ source: LocalProcess, exitCode: Int32?) {
        exited = true
        answerTimer?.invalidate()
        if flow.closesWhenPortaExits(status: exitCode) {
            window?.close()
            return
        }
        window?.title = "Porta — stopped"
        onCloseRefused?()
    }

    func dataReceived(slice: ArraySlice<UInt8>) {
        let (output, payloads) = filter.consume(slice)
        terminal.feed(byteArray: output[...])
        payloads.forEach(received)
    }

    func getWindowSize() -> winsize {
        let size = terminal.terminalDimensions
        return winsize(ws_row: UInt16(size.rows), ws_col: UInt16(size.cols), ws_xpixel: UInt16(terminal.bounds.width), ws_ypixel: UInt16(terminal.bounds.height))
    }

    // MARK: TerminalViewDelegate

    func sizeChanged(source: TerminalView, newCols: Int, newRows: Int) {
        guard process.running else {
            return
        }
        var size = getWindowSize()
        _ = ioctl(process.childfd, TIOCSWINSZ, &size)
    }

    func setTerminalTitle(source: TerminalView, title: String) {
        window?.title = title.isEmpty ? "Porta" : title
    }

    func hostCurrentDirectoryUpdate(source: TerminalView, directory: String?) {}

    func send(source: TerminalView, data: ArraySlice<UInt8>) {
        process.send(data: data)
    }

    func scrolled(source: TerminalView, position: Double) {}

    func clipboardCopy(source: TerminalView, content: Data) {
        guard let text = String(data: content, encoding: .utf8) else {
            return
        }
        NSPasteboard.general.clearContents()
        NSPasteboard.general.setString(text, forType: .string)
    }

    func rangeChanged(source: TerminalView, startY: Int, endY: Int) {}
}
