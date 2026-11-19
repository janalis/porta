import AppKit
import PortaDesktopCore

/// The system menu bar: the application's own menu, Porta's menus, then the window's.
final class MainMenu: NSObject, NSMenuDelegate {
    typealias Choose = (_ generation: String, _ menu: Int, _ item: Int) -> Void

    private let bar = NSMenu()
    private var shown: [NSMenuItem] = []
    private var generation: String?
    private var choose: Choose?
    private weak var recentTarget: AppDelegate?

    func install(target: AppDelegate) {
        // Before the menus: the Window menu holds Open Recent, which needs it.
        recentTarget = target
        bar.addItem(Self.submenu("Porta", [
            NSMenuItem(title: "About Porta", action: #selector(NSApplication.orderFrontStandardAboutPanel(_:)), keyEquivalent: ""),
            Self.item("Permissions…", #selector(AppDelegate.showPermissions(_:)), "", target),
            .separator(),
            NSMenuItem(title: "Hide Porta", action: #selector(NSApplication.hide(_:)), keyEquivalent: "h"),
            NSMenuItem(title: "Show All", action: #selector(NSApplication.unhideAllApplications(_:)), keyEquivalent: ""),
            .separator(),
            NSMenuItem(title: "Quit Porta", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "q"),
        ]))
        let fullScreen = NSMenuItem(title: "Enter Full Screen", action: #selector(NSWindow.toggleFullScreen(_:)), keyEquivalent: "f")
        fullScreen.keyEquivalentModifierMask = [.command, .control]
        let window = Self.submenu("Window", [
            Self.item("New Window", #selector(AppDelegate.newWindow(_:)), "n", target),
            Self.item("Open Folder…", #selector(AppDelegate.openFolder(_:)), "o", target),
            recentMenu(target: target),
            NSMenuItem(title: "Close Window", action: #selector(NSWindow.performClose(_:)), keyEquivalent: "w"),
            .separator(),
            Self.item("Bigger", #selector(AppDelegate.zoomIn(_:)), "+", target),
            Self.item("Smaller", #selector(AppDelegate.zoomOut(_:)), "-", target),
            Self.item("Actual Size", #selector(AppDelegate.zoomReset(_:)), "0", target),
            Self.item("Font…", #selector(AppDelegate.showFonts(_:)), "t", target),
            .separator(),
            // A menu built in code gets no tab items from AppKit: these are its own, sent to the key window.
            NSMenuItem(title: "Show Tab Bar", action: #selector(NSWindow.toggleTabBar(_:)), keyEquivalent: ""),
            NSMenuItem(title: "Show All Tabs", action: #selector(NSWindow.toggleTabOverview(_:)), keyEquivalent: ""),
            NSMenuItem(title: "Merge All Windows", action: #selector(NSWindow.mergeAllWindows(_:)), keyEquivalent: ""),
            NSMenuItem(title: "Move Tab to New Window", action: #selector(NSWindow.moveTabToNewWindow(_:)), keyEquivalent: ""),
            .separator(),
            fullScreen,
            NSMenuItem(title: "Minimize", action: #selector(NSWindow.performMiniaturize(_:)), keyEquivalent: "m"),
            NSMenuItem(title: "Zoom", action: #selector(NSWindow.performZoom(_:)), keyEquivalent: ""),
        ])
        bar.addItem(window)
        NSApp.mainMenu = bar
        NSApp.windowsMenu = window.submenu
    }

    /// Shows a window's menus. The same shape is updated in place, so an open menu stays open.
    func show(_ menuBar: MenuBar?, choose: @escaping Choose) {
        self.choose = choose
        guard let menuBar else {
            replace(with: [], generation: nil)
            return
        }
        if menuBar.generation == generation {
            refresh(menuBar)
        } else {
            replace(with: menuBar.menus, generation: menuBar.generation)
        }
    }

    private func replace(with menus: [MenuBar.Menu], generation: String?) {
        shown.forEach(bar.removeItem)
        shown = menus.enumerated().map { index, menu in
            let holder = NSMenuItem(title: menu.title, action: nil, keyEquivalent: "")
            let submenu = NSMenu(title: menu.title)
            submenu.autoenablesItems = false
            for (row, entry) in menu.items.enumerated() {
                submenu.addItem(entry.separator ? .separator() : makeItem(entry, menu: index, row: row))
            }
            holder.submenu = submenu
            bar.insertItem(holder, at: index + 1)
            return holder
        }
        self.generation = generation
    }

    private func refresh(_ menuBar: MenuBar) {
        for (holder, menu) in zip(shown, menuBar.menus) {
            for (item, entry) in zip(holder.submenu?.items ?? [], menu.items) where !entry.separator {
                item.isEnabled = entry.enabled
                item.state = entry.checked == true ? .on : .off
                item.attributedTitle = Self.title(entry)
            }
        }
    }

    private func makeItem(_ entry: MenuBar.Item, menu: Int, row: Int) -> NSMenuItem {
        let item = NSMenuItem(title: entry.label, action: #selector(chosen(_:)), keyEquivalent: "")
        item.target = self
        item.tag = menu << 16 | row
        item.isEnabled = entry.enabled
        item.state = entry.checked == true ? .on : .off
        item.attributedTitle = Self.title(entry)
        return item
    }

    func menuNeedsUpdate(_ menu: NSMenu) {
        menu.removeAllItems()
        let recent = NSDocumentController.shared.recentDocumentURLs
        for url in recent {
            let item = NSMenuItem(title: url.lastPathComponent, action: #selector(AppDelegate.openRecent(_:)), keyEquivalent: "")
            item.target = recentTarget
            item.representedObject = url
            item.image = NSWorkspace.shared.icon(forFile: url.path)
            item.image?.size = NSSize(width: 16, height: 16)
            item.toolTip = url.path
            menu.addItem(item)
        }
        if !recent.isEmpty {
            menu.addItem(.separator())
        }
        let clear = NSMenuItem(title: "Clear Menu", action: #selector(NSDocumentController.clearRecentDocuments(_:)), keyEquivalent: "")
        clear.target = NSDocumentController.shared
        clear.isEnabled = !recent.isEmpty
        menu.autoenablesItems = false
        menu.addItem(clear)
    }

    @objc private func chosen(_ sender: NSMenuItem) {
        guard let generation else { return }
        choose?(generation, sender.tag >> 16, sender.tag & 0xffff)
    }

    /// The shortcut is shown, never bound: Porta decides what a key means where the caret is.
    private static func title(_ entry: MenuBar.Item) -> NSAttributedString {
        let font = NSFont.menuFont(ofSize: 0)
        let style = NSMutableParagraphStyle()
        style.tabStops = [NSTextTab(textAlignment: .right, location: 320)]
        let title = NSMutableAttributedString(string: entry.label, attributes: [.font: font, .paragraphStyle: style])
        if !entry.shortcut.isEmpty {
            title.append(NSAttributedString(string: "\t" + entry.shortcut, attributes: [.font: font, .paragraphStyle: style, .foregroundColor: NSColor.secondaryLabelColor]))
        }
        return title
    }

    /// The folders opened lately, as the system remembers them, read each time the menu opens.
    private func recentMenu(target: AppDelegate) -> NSMenuItem {
        let holder = NSMenuItem(title: "Open Recent", action: nil, keyEquivalent: "")
        let menu = NSMenu(title: "Open Recent")
        menu.delegate = self
        recentTarget = target
        holder.submenu = menu
        return holder
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
