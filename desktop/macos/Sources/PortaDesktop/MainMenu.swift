import AppKit

/// The menu bar. Cmd chords Porta binds never get here: {@see KeyForwarding} takes them first.
enum MainMenu {
    static func build(target: AppDelegate) -> NSMenu {
        let bar = NSMenu()
        bar.addItem(submenu("Porta", [
            NSMenuItem(title: "About Porta", action: #selector(NSApplication.orderFrontStandardAboutPanel(_:)), keyEquivalent: ""),
            .separator(),
            NSMenuItem(title: "Hide Porta", action: #selector(NSApplication.hide(_:)), keyEquivalent: "h"),
            NSMenuItem(title: "Show All", action: #selector(NSApplication.unhideAllApplications(_:)), keyEquivalent: ""),
            .separator(),
            NSMenuItem(title: "Quit Porta", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "q"),
        ]))
        bar.addItem(submenu("File", [
            item("New Window", #selector(AppDelegate.newWindow(_:)), "n", target),
            item("Open Folder…", #selector(AppDelegate.openFolder(_:)), "o", target),
            .separator(),
            NSMenuItem(title: "Close Window", action: #selector(NSWindow.performClose(_:)), keyEquivalent: "w"),
        ]))
        bar.addItem(submenu("Edit", [
            NSMenuItem(title: "Paste", action: NSSelectorFromString("paste:"), keyEquivalent: "v"),
        ]))
        bar.addItem(submenu("View", [
            item("Bigger", #selector(AppDelegate.zoomIn(_:)), "+", target),
            item("Smaller", #selector(AppDelegate.zoomOut(_:)), "-", target),
            item("Actual Size", #selector(AppDelegate.zoomReset(_:)), "0", target),
            .separator(),
            NSMenuItem(title: "Enter Full Screen", action: #selector(NSWindow.toggleFullScreen(_:)), keyEquivalent: "f"),
        ]))
        bar.items.last?.submenu?.items.last?.keyEquivalentModifierMask = [.command, .control]
        let window = submenu("Window", [
            NSMenuItem(title: "Minimize", action: #selector(NSWindow.performMiniaturize(_:)), keyEquivalent: "m"),
            NSMenuItem(title: "Zoom", action: #selector(NSWindow.performZoom(_:)), keyEquivalent: ""),
        ])
        NSApp.windowsMenu = window.submenu
        bar.addItem(window)
        return bar
    }

    private static func item(_ title: String, _ action: Selector, _ key: String, _ target: AnyObject) -> NSMenuItem {
        let item = NSMenuItem(title: title, action: action, keyEquivalent: key)
        item.target = target
        return item
    }

    private static func submenu(_ title: String, _ items: [NSMenuItem]) -> NSMenuItem {
        let holder = NSMenuItem(title: title, action: nil, keyEquivalent: "")
        let menu = NSMenu(title: title)
        items.forEach(menu.addItem)
        holder.submenu = menu
        return holder
    }
}
