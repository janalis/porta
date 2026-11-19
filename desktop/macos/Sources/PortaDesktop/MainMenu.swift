import AppKit
import PortaDesktopCore

/// The system menu bar: the application's own menu, Porta's menus, then the window's.
final class MainMenu: NSObject {
    typealias Choose = (_ generation: String, _ menu: Int, _ item: Int) -> Void

    private let bar = NSMenu()
    private var shown: [NSMenuItem] = []
    private var generation: String?
    private var choose: Choose?

    func install(target: AppDelegate) {
        bar.addItem(Self.submenu("Porta", [
            NSMenuItem(title: "About Porta", action: #selector(NSApplication.orderFrontStandardAboutPanel(_:)), keyEquivalent: ""),
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
            NSMenuItem(title: "Close Window", action: #selector(NSWindow.performClose(_:)), keyEquivalent: "w"),
            .separator(),
            Self.item("Bigger", #selector(AppDelegate.zoomIn(_:)), "+", target),
            Self.item("Smaller", #selector(AppDelegate.zoomOut(_:)), "-", target),
            Self.item("Actual Size", #selector(AppDelegate.zoomReset(_:)), "0", target),
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
