import AppKit
import PortaDesktopCore
import SwiftTerm
import UserNotifications

/// One window running one Porta.
final class ProjectWindowController: NSWindowController, NSWindowDelegate, TerminalViewDelegate, LocalProcessDelegate {
    private static let answerSeconds: TimeInterval = 4
    private static let shutdownSeconds: TimeInterval = 15
    private static let settingsPath = DesktopSettings.path(environment: ProcessInfo.processInfo.environment, home: NSHomeDirectory())
    private static var settings = DesktopSettings.parse(FileManager.default.contents(atPath: settingsPath))
    private static var defaultFontSize: CGFloat { CGFloat(settings.fontSize ?? 13) }
    private static let fontSizeKey = "fontSize"

    var onClosed: (() -> Void)?
    var onCloseRefused: (() -> Void)?
    private(set) var projectKey: String?

    private var plan: LaunchPlan
    private let runtime: String
    /// Paths for this project that arrived while Porta was starting, sent once it is ready.
    private var pending: [String] = []
    private let token: String
    private let terminal: TerminalView
    private var process: LocalProcess!
    private var filter = OscFilter()
    private var flow = CloseFlow()
    private var answerTimer: Timer?
    private var exited = false
    private var ready = false
    private var dropView: DropView?
    /// Files and folders dropped on the window, for the application to send where they belong.
    var onDropped: (([String]) -> Void)? {
        get { dropView?.onDropped }
        set { dropView?.onDropped = newValue }
    }

    init(runtime: String, project: String?) {
        token = LaunchPlan.newToken()
        self.runtime = runtime
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
        let holder = DropView(holding: terminal)
        window.contentView = holder
        dropView = holder
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

    /// A file or folder for this window's project, from Finder or the Dock.
    func open(path: String) {
        if ready {
            send(DesktopMessage.openRequest(token: token, path: path))
        } else {
            pending.append(path)
        }
        focus()
    }

    /// A run ended. Told only when this window is not the one in front: the user is elsewhere.
    private func notify(_ text: String) {
        guard !(NSApp.isActive && window?.isKeyWindow == true) else { return }
        let content = UNMutableNotificationContent()
        content.title = window?.title ?? "Porta"
        content.body = text
        content.userInfo = ["window": token]
        let center = UNUserNotificationCenter.current()
        center.requestAuthorization(options: [.alert, .sound]) { granted, _ in
            DispatchQueue.main.async {
                if granted {
                    center.add(UNNotificationRequest(identifier: UUID().uuidString, content: content, trigger: nil))
                } else {
                    // Notifications turned off for Porta: the Dock icon bounces once instead.
                    NSApp.requestUserAttention(.informationalRequest)
                }
            }
        }
    }

    /// Whether a notification came from this window.
    func posted(_ notification: UNNotification) -> Bool {
        notification.request.content.userInfo["window"] as? String == token
    }

    /// The system's light or dark mode, for Porta and for the window around it.
    func sendAppearance() {
        let dark = NSApp.effectiveAppearance.bestMatch(from: [.darkAqua, .aqua]) == .darkAqua
        terminal.nativeBackgroundColor = dark ? .black : .white
        terminal.nativeForegroundColor = dark ? NSColor(white: 0.85, alpha: 1) : NSColor(white: 0.1, alpha: 1)
        window?.backgroundColor = terminal.nativeBackgroundColor
        if ready {
            send(DesktopMessage.appearance(token: token, dark: dark))
        }
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
        // A family as desktop.json names it ("JetBrains Mono"), or a face's own name ("Menlo-Regular").
        let named = settings.fontFamily.flatMap { NSFont(name: $0, size: size) ?? NSFontManager.shared.font(withFamily: $0, traits: [], weight: 5, size: size) }
        return named ?? NSFont.monospacedSystemFont(ofSize: size, weight: .regular)
    }

    /// The font this window shows, for the font panel to start from.
    var currentFont: NSFont { terminal.font }

    /// A font chosen in the font panel: written to desktop.json, which every window reads.
    static func choose(family: String, size: CGFloat) {
        let written = DesktopSettings.written(FileManager.default.contents(atPath: settingsPath), fontFamily: family, fontSize: Double(size))
        try? FileManager.default.createDirectory(atPath: (settingsPath as NSString).deletingLastPathComponent, withIntermediateDirectories: true)
        FileManager.default.createFile(atPath: settingsPath, contents: written)
        settings = DesktopSettings.parse(written)
        // The size is the file's now: Bigger and Smaller start again from it.
        UserDefaults.standard.removeObject(forKey: fontSizeKey)
    }

    /// The font desktop.json names, after a choice in another window.
    func applySavedFont() {
        terminal.font = Self.font(size: Self.savedFontSize)
    }

    private func received(_ payload: String) {
        guard let message = DesktopMessage.parse(payload: payload, token: token) else {
            return
        }
        if case let .ready(root) = message {
            projectKey = ProjectKey.of(root)
            let folder = URL(fileURLWithPath: root)
            // Cmd-click on the title shows where the project is; the system remembers it for Open Recent.
            window?.representedURL = folder
            NSDocumentController.shared.noteNewRecentDocumentURL(folder)
            ready = true
            // Reopen starts what is open now, which a switch may have changed.
            plan = LaunchPlan(runtime: runtime, project: root, inherited: ProcessInfo.processInfo.environment, token: token, home: NSHomeDirectory())
            sendAppearance()
            pending.forEach { send(DesktopMessage.openRequest(token: token, path: $0)) }
            pending.removeAll()
        }
        if case let .notify(text) = message {
            notify(text)
        }
        if case let .state(unsaved) = message {
            // The dot in the close button: closing this window would lose work.
            window?.isDocumentEdited = unsaved > 0
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
        offerRecovery()
    }

    /// A window whose Porta stopped says what can be done, rather than just staying.
    private func offerRecovery() {
        guard let window else { return }
        let alert = NSAlert()
        alert.messageText = "Porta stopped"
        alert.informativeText = "What it printed is behind this message. The project's .porta/error.log may say more."
        alert.addButton(withTitle: "Reopen")
        alert.addButton(withTitle: "Show Log")
        alert.addButton(withTitle: "Close")
        alert.beginSheetModal(for: window) { [weak self] answer in
            switch answer {
            case .alertFirstButtonReturn:
                self?.restart()
            case .alertSecondButtonReturn:
                self?.showLog()
            default:
                self?.window?.close()
            }
        }
    }

    private func showLog() {
        let folder = URL(fileURLWithPath: projectKey ?? NSHomeDirectory()).appendingPathComponent(".porta")
        let log = folder.appendingPathComponent("error.log")
        NSWorkspace.shared.activateFileViewerSelecting([FileManager.default.fileExists(atPath: log.path) ? log : folder])
        offerRecovery()
    }

    /// The same project, started again in this window.
    private func restart() {
        exited = false
        ready = false
        flow = CloseFlow()
        filter = OscFilter()
        window?.title = "Porta"
        // RIS: what the stopped Porta left on screen goes.
        terminal.feed(text: "\u{1b}c")
        process = LocalProcess(delegate: self, dispatchQueue: .main)
        process.startProcess(executable: plan.executable, args: plan.arguments, environment: plan.environment, currentDirectory: plan.workingDirectory)
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
