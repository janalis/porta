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
        menus.install(target: self)
        UNUserNotificationCenter.current().delegate = self
        NSWindow.allowsAutomaticWindowTabbing = false
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
    @objc func showFonts(_ sender: Any?) {
        let manager = NSFontManager.shared
        manager.target = self
        if let font = focused?.currentFont {
            manager.setSelectedFont(font, isMultiple: false)
        }
        manager.orderFrontFontPanel(sender)
    }

    /// A font picked in the panel: every window takes it, and new ones read it from desktop.json.
    @objc func changeFont(_ sender: NSFontManager?) {
        guard let sender, let current = focused?.currentFont ?? windows.first?.currentFont else { return }
        let chosen = sender.convert(current)
        ProjectWindowController.choose(family: chosen.familyName ?? chosen.fontName, size: chosen.pointSize)
        windows.forEach { $0.applySavedFont() }
    }

    @objc func zoomIn(_ sender: Any?) { focused?.zoom(by: 1) }
    @objc func zoomOut(_ sender: Any?) { focused?.zoom(by: -1) }
    @objc func zoomReset(_ sender: Any?) { focused?.zoomReset() }
}
