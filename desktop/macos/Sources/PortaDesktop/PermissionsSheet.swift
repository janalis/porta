import AppKit
import PortaDesktopCore
import UserNotifications

/// What Porta may need macOS to allow, each with its state and the way to grant it: asked
/// directly where the system can ask, or System Settings opened at the pane that grants it.
final class PermissionsSheet: NSWindowController {
    private enum Access {
        case allowed
        case askable
        case refused
        case askedWhenNeeded
        /// The system will not even ask: notifications need a signed build (a team identity).
        case unavailable
    }

    /// macOS turned the request down without asking: true for a build with no team identity.
    private var notificationsUnavailable = false

    private let project: String?
    private let rows = NSStackView()
    private var onDone: (() -> Void)?
    private var activation: NSObjectProtocol?

    /// - Parameter project: the project a window opens, when the question is about it.
    init(project: String?) {
        self.project = project
        let window = NSWindow(contentRect: NSRect(x: 0, y: 0, width: 520, height: 360), styleMask: [.titled, .fullSizeContentView], backing: .buffered, defer: true)
        window.titlebarAppearsTransparent = true
        window.titleVisibility = .hidden
        window.isMovableByWindowBackground = true
        super.init(window: window)
        window.contentView = layout()
        refresh()
        activation = NotificationCenter.default.addObserver(forName: NSApplication.didBecomeActiveNotification, object: nil, queue: .main) { [weak self] _ in
            // Back from System Settings: what was granted there shows.
            self?.refresh()
        }
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) {
        fatalError("built in code")
    }

    deinit {
        activation.map(NotificationCenter.default.removeObserver)
    }

    /// Over `parent` as a sheet, else as a window of its own; `done` runs once it is closed.
    func present(over parent: NSWindow?, done: @escaping () -> Void = {}) {
        onDone = done
        guard let window else { return }
        if let parent {
            parent.beginSheet(window)
        } else {
            window.center()
            showWindow(nil)
            NSApp.activate(ignoringOtherApps: true)
        }
    }

    // MARK: layout

    private func layout() -> NSView {
        let icon = NSImageView(image: NSApp.applicationIconImage)
        icon.imageScaling = .scaleProportionallyUpOrDown
        icon.widthAnchor.constraint(equalToConstant: 64).isActive = true
        icon.heightAnchor.constraint(equalToConstant: 64).isActive = true

        let title = NSTextField(labelWithString: "Porta needs your permission")
        title.font = .systemFont(ofSize: 20, weight: .semibold)
        let subtitle = NSTextField(wrappingLabelWithString: "Porta works with your projects and tells you when a run ends. macOS asks you to allow each once.")
        subtitle.alignment = .center
        subtitle.textColor = .secondaryLabelColor
        subtitle.preferredMaxLayoutWidth = 420

        rows.orientation = .vertical
        rows.spacing = 0
        rows.alignment = .leading
        let box = NSView()
        box.wantsLayer = true
        box.layer?.cornerRadius = 10
        box.layer?.borderWidth = 1
        box.layer?.borderColor = NSColor.separatorColor.cgColor
        box.layer?.backgroundColor = NSColor.controlBackgroundColor.cgColor
        rows.translatesAutoresizingMaskIntoConstraints = false
        box.addSubview(rows)
        NSLayoutConstraint.activate([
            rows.leadingAnchor.constraint(equalTo: box.leadingAnchor),
            rows.trailingAnchor.constraint(equalTo: box.trailingAnchor),
            rows.topAnchor.constraint(equalTo: box.topAnchor),
            rows.bottomAnchor.constraint(equalTo: box.bottomAnchor),
            box.widthAnchor.constraint(equalToConstant: 456),
        ])

        let later = NSButton(title: "Not Now", target: self, action: #selector(finish))
        let next = NSButton(title: "Continue", target: self, action: #selector(finish))
        next.keyEquivalent = "\r"
        let buttons = NSStackView(views: [NSView(), later, next])
        buttons.widthAnchor.constraint(equalToConstant: 456).isActive = true

        let stack = NSStackView(views: [icon, title, subtitle, box, buttons])
        stack.orientation = .vertical
        stack.alignment = .centerX
        stack.spacing = 12
        stack.setCustomSpacing(20, after: subtitle)
        stack.setCustomSpacing(20, after: box)
        stack.edgeInsets = NSEdgeInsets(top: 36, left: 32, bottom: 24, right: 32)
        return stack
    }

    private func row(symbol: String, tint: NSColor, title: String, detail: String, access: Access, grant: Selector?) -> NSView {
        let badge = NSImageView(image: NSImage(systemSymbolName: symbol, accessibilityDescription: nil) ?? NSImage())
        badge.symbolConfiguration = .init(pointSize: 15, weight: .medium)
        badge.contentTintColor = .white
        // Decoration: the row's title says what it is (a reader announced the folder as "move").
        badge.setAccessibilityElement(false)
        badge.wantsLayer = true
        badge.layer?.backgroundColor = tint.cgColor
        badge.layer?.cornerRadius = 7
        badge.widthAnchor.constraint(equalToConstant: 30).isActive = true
        badge.heightAnchor.constraint(equalToConstant: 30).isActive = true

        let name = NSTextField(labelWithString: title)
        name.font = .systemFont(ofSize: 13, weight: .semibold)
        let about = NSTextField(wrappingLabelWithString: detail)
        about.font = .systemFont(ofSize: 11)
        about.textColor = .secondaryLabelColor
        about.preferredMaxLayoutWidth = 250
        let words = NSStackView(views: [name, about])
        words.orientation = .vertical
        words.alignment = .leading
        words.spacing = 2

        let line = NSStackView(views: [badge, words, NSView(), accessory(access, grant: grant)])
        line.spacing = 12
        line.edgeInsets = NSEdgeInsets(top: 12, left: 14, bottom: 12, right: 14)
        line.widthAnchor.constraint(equalToConstant: 456).isActive = true
        return line
    }

    private func accessory(_ access: Access, grant: Selector?) -> NSView {
        switch access {
        case .allowed:
            let done = NSTextField(labelWithAttributedString: {
                let text = NSMutableAttributedString(attachment: {
                    let mark = NSTextAttachment()
                    mark.image = NSImage(systemSymbolName: "checkmark.circle.fill", accessibilityDescription: "allowed")?
                        .withSymbolConfiguration(.init(paletteColors: [.white, .systemGreen]))
                    return mark
                }())
                text.append(NSAttributedString(string: " Allowed"))
                return text
            }())
            done.textColor = .secondaryLabelColor
            return done
        case .unavailable:
            let none = NSTextField(labelWithString: "Not in this build")
            none.textColor = .tertiaryLabelColor
            none.font = .systemFont(ofSize: 11)
            return none
        case .askedWhenNeeded:
            let later = NSTextField(labelWithString: "Asked when needed")
            later.textColor = .tertiaryLabelColor
            later.font = .systemFont(ofSize: 11)
            return later
        case .askable, .refused:
            let button = NSButton(title: access == .askable ? "Allow" : "Open Settings", target: self, action: grant)
            button.bezelStyle = .push
            button.controlSize = .regular
            return button
        }
    }

    // MARK: state

    private func refresh() {
        guard Bundle.main.bundleIdentifier != nil else { return show(notifications: .askedWhenNeeded) }
        UNUserNotificationCenter.current().getNotificationSettings { settings in
            let access: Access = switch settings.authorizationStatus {
            case .authorized, .provisional, .ephemeral: .allowed
            case .denied: .refused
            default: self.notificationsUnavailable ? .unavailable : .askable
            }
            DispatchQueue.main.async { self.show(notifications: access) }
        }
    }

    private func show(notifications: Access) {
        rows.arrangedSubviews.forEach { $0.removeFromSuperview() }
        let detail = notifications == .unavailable
            ? "macOS lets only a signed Porta send them; the Dock icon bounces instead"
            : "When a run ends while you are in another window or application"
        rows.addArrangedSubview(row(symbol: "bell.badge.fill", tint: .systemRed, title: "Notifications", detail: detail, access: notifications, grant: notifications == .askable ? #selector(askNotifications) : #selector(openNotificationSettings)))
        let separator = NSBox()
        separator.boxType = .separator
        rows.addArrangedSubview(separator)
        rows.addArrangedSubview(filesRow())
    }

    private func filesRow() -> NSView {
        guard let project, let folder = ProtectedLocation.of(project, home: NSHomeDirectory()) else {
            return row(symbol: "folder.fill", tint: .systemBlue, title: "Files and Folders", detail: "For a project in Desktop, Documents, Downloads, iCloud Drive or on an external disk", access: .askedWhenNeeded, grant: nil)
        }
        let name = (project as NSString).lastPathComponent
        let readable = (try? FileManager.default.contentsOfDirectory(atPath: project)) != nil
        return row(symbol: "folder.fill", tint: .systemBlue, title: "Files and Folders", detail: readable ? "\"\(name)\" in \(folder)" : "\"\(name)\" is in \(folder), which macOS keeps from Porta", access: readable ? .allowed : .refused, grant: #selector(openFilesSettings))
    }

    /// Whether `project` lies where macOS guards it and Porta may not read it yet.
    static func blocks(_ project: String?) -> Bool {
        guard let project, ProtectedLocation.of(project, home: NSHomeDirectory()) != nil else { return false }
        // Reading it is what asks the system's own question, the first time.
        return (try? FileManager.default.contentsOfDirectory(atPath: project)) == nil
    }

    // MARK: actions

    @objc private func askNotifications() {
        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound]) { [weak self] _, error in
            DispatchQueue.main.async {
                // Turned down with no question asked (UNErrorDomain 1): say so, rather than leave a button that does nothing.
                if let error = error as NSError?, error.domain == UNErrorDomain, error.code == UNError.Code.notificationsNotAllowed.rawValue {
                    self?.notificationsUnavailable = true
                }
                self?.refresh()
            }
        }
    }

    @objc private func openNotificationSettings() {
        open("x-apple.systempreferences:com.apple.Notifications-Settings.extension?id=" + (Bundle.main.bundleIdentifier ?? ""))
    }

    @objc private func openFilesSettings() {
        open("x-apple.systempreferences:com.apple.preference.security?Privacy_FilesAndFolders")
    }

    private func open(_ address: String) {
        if let url = URL(string: address) {
            NSWorkspace.shared.open(url)
        }
    }

    @objc private func finish() {
        guard let window else { return }
        if let parent = window.sheetParent {
            parent.endSheet(window)
        } else {
            window.close()
        }
        onDone?()
        onDone = nil
    }
}
