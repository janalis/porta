import AppKit
import PortaDesktopCore

/// One process, one window per project: LaunchServices hands every further launch to this instance.
final class AppDelegate: NSObject, NSApplicationDelegate {
    private var windows: [ProjectWindowController] = []
    private let menus = MainMenu()
    private var quitting = false
    private var openedAtLaunch = false

    func applicationDidFinishLaunching(_ notification: Notification) {
        menus.install(target: self)
        NSWindow.allowsAutomaticWindowTabbing = false
        KeyForwarding.install { [weak self] in self?.focused }
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

    private func open(project: String) {
        let key = ProjectKey.of(project)
        if let existing = windows.first(where: { $0.projectKey == key }) {
            existing.focus()
            return
        }
        openWindow(project: project)
    }

    private func openWindow(project: String?) {
        guard let runtime = Bundle.main.resourceURL?.appendingPathComponent("runtime").path else {
            return
        }
        let controller = ProjectWindowController(runtime: runtime, project: project)
        controller.onClosed = { [weak self, weak controller] in
            guard let self, let controller else { return }
            self.windows.removeAll { $0 === controller }
            if self.quitting && self.windows.isEmpty {
                NSApp.reply(toApplicationShouldTerminate: true)
            }
        }
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

    @objc func newWindow(_ sender: Any?) {
        openWindow(project: nil)
    }

    @objc func zoomIn(_ sender: Any?) { focused?.zoom(by: 1) }
    @objc func zoomOut(_ sender: Any?) { focused?.zoom(by: -1) }
    @objc func zoomReset(_ sender: Any?) { focused?.zoomReset() }
}
