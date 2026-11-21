import Foundation

/// The conversation with Porta, as `desktop/contract/protocol.json` defines it.
public enum DesktopMessage: Equatable {
    case ready(root: String)
    case accepted
    case closing
    case menu(MenuBar)
    case state(unsaved: Int)
    /// A run ended; the text says which and how.
    case notify(text: String)
    /// No project yet: Porta's picker is up, and takes a folder sent as an open request.
    case picking
    /// The picker asks for the system's folder dialog.
    case chooseFolder

    public static let oscCode = 7777
    private static let namespace = "porta-desktop"

    /// Reads an OSC 7777 payload. Anything without this session's token is not from this session's Porta.
    public static func parse(payload: String, token: String) -> DesktopMessage? {
        let fields = payload.split(separator: ";", omittingEmptySubsequences: false).map(String.init)
        guard fields.count >= 3, fields[0] == namespace, fields[1] == token else {
            return nil
        }
        switch (fields[2], fields.count) {
        case ("ready", 4):
            guard let data = Data(base64Encoded: fields[3]), let root = String(data: data, encoding: .utf8) else {
                return nil
            }
            return .ready(root: root)
        case ("accepted", 3):
            return .accepted
        case ("closing", 3):
            return .closing
        case ("picking", 3):
            return .picking
        case ("chooseFolder", 3):
            return .chooseFolder
        case ("state", 4):
            guard let unsaved = Int(fields[3]), unsaved >= 0 else {
                return nil
            }
            return .state(unsaved: unsaved)
        case ("notify", 4):
            guard let data = Data(base64Encoded: fields[3]), let text = String(data: data, encoding: .utf8) else {
                return nil
            }
            return .notify(text: text)
        case ("menu", 4):
            guard let data = Data(base64Encoded: fields[3]), let menu = try? JSONDecoder().decode(MenuBar.self, from: data) else {
                return nil
            }
            return .menu(menu)
        default:
            return nil
        }
    }

    /// What choosing a system menu item sends.
    public static func menuChoice(token: String, generation: String, menu: Int, item: Int) -> String {
        "\u{1b}]\(oscCode);\(namespace);\(token);menu;\(generation);\(menu);\(item)\u{1b}\\"
    }

    /// A file or folder the system handed the application, for the window that has its project.
    public static func openRequest(token: String, path: String) -> String {
        "\u{1b}]\(oscCode);\(namespace);\(token);open;\(Data(path.utf8).base64EncodedString())\u{1b}\\"
    }

    /// The system's light or dark mode, followed while no theme was chosen in Porta.
    public static func appearance(token: String, dark: Bool) -> String {
        "\u{1b}]\(oscCode);\(namespace);\(token);appearance;\(dark ? "dark" : "light")\u{1b}\\"
    }

    /// The request the close button sends.
    public static func closeRequest(token: String) -> String {
        "\u{1b}]\(oscCode);\(namespace);\(token);close\u{1b}\\"
    }
}
