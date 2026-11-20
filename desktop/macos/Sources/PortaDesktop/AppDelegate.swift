import AppKit
import PortaDesktopCore
import UserNotifications

/// One process, one window per project: LaunchServices hands every further launch to this instance.
final class AppDelegate: NSObject, NSApplicationDelegate, UNUserNotificationCenterDelegate {
    private var windows: [ProjectWindowController] = []
    private let menus = MainMenu()
    private var quitting = false
    private var openedAtLaunch = false
    private var appearance: NSKeyValueObservation?

    func applicationDidFinishLaunching(_ notification: Notification) {
        // Tabs as the system prefers them (Desktop & Dock → Prefer tabs): projects can share a window.
        // The Window menu's tab items are MainMenu's own: AppKit adds none to a menu built in code.
        NSWindow.allowsAutomaticWindowTabbing = true
        menus.install(target: self)
        // The notification centre needs an application bundle; the bare executable of a build has none.
        if Bundle.main.bundleIdentifier != nil {
            UNUserNotificationCenter.current().delegate = self
        }
        KeyForwarding.install { [weak self] in self?.focused }
        appearance = NSApp.observe(\.effectiveAppearance) { [weak self] _, _ in
            DispatchQueue.main.async { self?.windows.forEach { $0.sendAppearance() } }
        }
        if !openedAtLaunch {
            openWindow(project: nil)
        }
        NSApp.activate(ignoringOtherApps: true)
    }

    func application(_ application: NSApplication, open urls: [URL]) {
        openedAtLaunch = true
        for url in urls where url.isFileURL {
            open(project: url.path)
        }
    }

    func applicationShouldHandleReopen(_ sender: NSApplication, hasVisibleWindows visible: Bool) -> Bool {
        if let last = windows.last {
            last.focus()
        } else {
            openWindow(project: nil)
        }
        return false
    }

    func applicationShouldTerminate(_ sender: NSApplication) -> NSApplication.TerminateReply {
        guard !windows.isEmpty else {
            return .terminateNow
        }
        quitting = true
        windows.forEach { $0.requestClose() }
        return .terminateLater
    }

    func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool {
        true
    }

    private var focused: ProjectWindowController? {
        windows.first { $0.window?.isKeyWindow == true }
    }

    private func open(project path: String) {
        let key = ProjectKey.of(path)
        if let existing = windows.first(where: { $0.projectKey == key }) {
            existing.focus()
            return
        }
        // A file, or a folder inside a project already open, goes to that window.
        if let holder = windows.first(where: { $0.projectKey.map { ProjectKey.contains($0, path) } ?? false }) {
            holder.open(path: path)
            return
        }
        openWindow(project: path)
    }

    private func openWindow(project: String?) {
        guard let runtime = Bundle.main.resourceURL?.appendingPathComponent("runtime").path else {
            return
        }
        let controller = ProjectWindowController(runtime: runtime, project: project)
        controller.onDropped = { [weak self] paths in
            paths.forEach { self?.open(project: $0) }
        }
        controller.onClosed = { [weak self, weak controller] in
            guard let self, let controller else { return }
            self.windows.removeAll { $0 === controller }
            if self.quitting && self.windows.isEmpty {
                NSApp.reply(toApplicationShouldTerminate: true)
            }
        }
        controller.sendAppearance()
        controller.onMenuChanged = { [weak self] window in
            guard window.window?.isKeyWindow == true else { return }
            self?.menus.show(window.menuBar, choose: { [weak window] generation, menu, item in
                window?.chooseMenuItem(generation: generation, menu: menu, item: item)
            })
        }
        controller.onCloseRefused = { [weak self] in
            guard let self, self.quitting else { return }
            self.quitting = false
            NSApp.reply(toApplicationShouldTerminate: false)
        }
        windows.append(controller)
        controller.start()
        // The first launch says what Porta may ask macOS for, once; a blocked project already did.
        if !UserDefaults.standard.bool(forKey: Self.permissionsIntroduced) {
            UserDefaults.standard.set(true, forKey: Self.permissionsIntroduced)
            controller.showPermissions()
        }
    }

    private static let permissionsIntroduced = "permissionsIntroduced"
    private var permissions: PermissionsSheet?

    /// Porta → Permissions…: over the window in front, or on its own when there is none.
    @objc func showPermissions(_ sender: Any?) {
        if let window = focused ?? windows.first {
            window.showPermissions()
            return
        }
        let sheet = PermissionsSheet(project: nil)
        permissions = sheet
        sheet.present(over: nil) { [weak self] in self?.permissions = nil }
    }

    @objc func openFolder(_ sender: Any?) {
        let panel = NSOpenPanel()
        panel.canChooseFiles = false
        panel.canChooseDirectories = true
        panel.allowsMultipleSelection = false
        if panel.runModal() == .OK, let url = panel.url {
            open(project: url.path)
        }
    }

    /// A notification clicked: the window it came from comes forward.
    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse, withCompletionHandler done: @escaping () -> Void) {
        // Delegate calls may come on another queue; the windows are the main thread's.
        DispatchQueue.main.async { [weak self] in
            self?.windows.first { $0.posted(response.notification) }?.focus()
            done()
        }
    }

    /// Shown while Porta is in front too: it was posted because its own window was not.
    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification, withCompletionHandler present: @escaping (UNNotificationPresentationOptions) -> Void) {
        present([.banner, .sound])
    }

    @objc func openRecent(_ sender: NSMenuItem) {
        if let url = sender.representedObject as? URL {
            open(project: url.path)
        }
    }

    @objc func newWindow(_ sender: Any?) {
        openWindow(project: nil)
    }

    /// The system's font panel, on the font of the window in front.
    private weak var fontSource: ProjectWindowController?

    @objc func showFonts(_ sender: Any?) {
        let manager = NSFontManager.shared
        manager.target = self
        // The panel becomes key: the window it was opened from is the one its choices convert from.
        fontSource = focused
        if let font = focused?.currentFont {
            manager.setSelectedFont(font, isMultiple: false)
        }
        manager.orderFrontFontPanel(sender)
    }

    /// A font picked in the panel: every window takes it, and new ones read it from desktop.json.
    @objc func changeFont(_ sender: NSFontManager?) {
        guard let sender, let current = (fontSource ?? focused ?? windows.first)?.currentFont else { return }
        let chosen = sender.convert(current)
        let pick = FontPick.judge(currentName: current.fontName, currentSize: Double(current.pointSize), chosenName: chosen.fontName, chosenFamily: chosen.familyName, chosenSize: Double(chosen.pointSize), fixedPitch: chosen.isFixedPitch)
        guard case let .chosen(family, size) = pick else {
            // The panel lists every font; a terminal's grid needs one whose letters are all as wide.
            if pick == .refused {
                NSSound.beep()
            }
            return
        }
        do {
            try ProjectWindowController.choose(family: family, size: CGFloat(size))
        } catch {
            let alert = NSAlert()
            alert.messageText = "The font could not be saved"
            alert.informativeText = "\(ProjectWindowController.settingsFile): \(error.localizedDescription)"
            alert.runModal()
            return
        }
        windows.forEach { $0.applySavedFont() }
    }

    @objc func zoomIn(_ sender: Any?) { focused?.zoom(by: 1) }
    @objc func zoomOut(_ sender: Any?) { focused?.zoom(by: -1) }
    @objc func zoomReset(_ sender: Any?) { focused?.zoomReset() }
}
