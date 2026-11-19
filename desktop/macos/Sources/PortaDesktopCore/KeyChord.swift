/// A Cmd chord written as the keymap names it (`shift+super+z`), and the CSI-u bytes that say it to Porta.
public struct KeyChord: Hashable {
    public let key: String
    public let shift: Bool
    public let option: Bool
    public let control: Bool

    /// The chords Porta binds on macOS: `desktop/contract/keys.json` holds this list against the keymap.
    public static let forwarded: Set<KeyChord> = [
        KeyChord(key: "c"), KeyChord(key: "x"), KeyChord(key: "a"), KeyChord(key: "z"), KeyChord(key: "z", shift: true),
        KeyChord(key: "s"), KeyChord(key: "s", option: true), KeyChord(key: "f"), KeyChord(key: "r"), KeyChord(key: "d"),
        KeyChord(key: "/"), KeyChord(key: "l"), KeyChord(key: "e"), KeyChord(key: "c", shift: true), KeyChord(key: "backspace"),
    ]

    public init(key: String, shift: Bool = false, option: Bool = false, control: Bool = false) {
        self.key = key
        self.shift = shift
        self.option = option
        self.control = control
    }

    /// Lowercase codepoint and the modifier parameter: Porta's parser reads no other spelling.
    public var sequence: String {
        let codepoint = key == "backspace" ? 127 : Int(key.unicodeScalars.first?.value ?? 0)
        let modifiers = 1 + 8 + (shift ? 1 : 0) + (option ? 2 : 0) + (control ? 4 : 0)
        return "\u{1b}[\(codepoint);\(modifiers)u"
    }
}
