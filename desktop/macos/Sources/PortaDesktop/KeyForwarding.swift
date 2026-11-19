import AppKit
import PortaDesktopCore

/// Hands Porta the Cmd chords its keymap binds, before the menu bar can take them.
enum KeyForwarding {
    private static let backspace: UInt16 = 51

    static func install(focused: @escaping () -> ProjectWindowController?) {
        NSEvent.addLocalMonitorForEvents(matching: .keyDown) { event in
            guard let chord = chord(of: event), let window = focused() else {
                return event
            }
            if chord == KeyChord(key: "v") {
                window.pasteClipboard()
                return nil
            }
            guard KeyChord.forwarded.contains(chord) else {
                return event
            }
            window.send(chord.sequence)
            return nil
        }
    }

    private static func chord(of event: NSEvent) -> KeyChord? {
        let flags = event.modifierFlags.intersection(.deviceIndependentFlagsMask)
        guard flags.contains(.command) else {
            return nil
        }
        // Without Shift or Option applied: the keymap names the key, not the character it types.
        let key = event.keyCode == backspace ? "backspace" : event.charactersIgnoringModifiers?.lowercased() ?? ""
        return KeyChord(key: key, shift: flags.contains(.shift), option: flags.contains(.option), control: flags.contains(.control))
    }
}
