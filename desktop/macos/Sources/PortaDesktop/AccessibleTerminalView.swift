import AppKit
import PortaDesktopCore
import SwiftTerm

/// The terminal, as VoiceOver reads it: a text area holding the screen's lines, with the
/// cursor as its insertion point. SwiftTerm's macOS view exposes nothing to accessibility.
final class AccessibleTerminalView: TerminalView {
    private var announce: DispatchWorkItem?

    /// What changed on screen, told to assistive technology at most every half second:
    /// a full-screen application redraws far more often than anyone can listen.
    func screenChanged() {
        guard NSWorkspace.shared.isVoiceOverEnabled, announce == nil else { return }
        let work = DispatchWorkItem { [weak self] in
            guard let self else { return }
            self.announce = nil
            NSAccessibility.post(element: self, notification: .valueChanged)
            NSAccessibility.post(element: self, notification: .selectedTextChanged)
        }
        announce = work
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.5, execute: work)
    }

    /// The visible screen, copied once per question: its rows, where each starts, the cursor.
    private struct Screen {
        let lines: [NSString]
        let starts: [Int]
        let text: NSString
        let cursorRow: Int
        let cursorOffset: Int

        init(_ snapshot: TerminalViewStateSnapshot) {
            lines = snapshot.visibleRows.map { ReadableLine.of($0.text) as NSString }
            var offset = 0
            var starts: [Int] = []
            for line in lines {
                starts.append(offset)
                offset += line.length + 1
            }
            self.starts = starts
            text = lines.map { $0 as String }.joined(separator: "\n") as NSString
            cursorRow = min(max(snapshot.cursor.row, 0), max(lines.count - 1, 0))
            // A screen column is not a text offset once a row holds wide characters or emoji.
            let row = snapshot.visibleRows.indices.contains(cursorRow) ? snapshot.visibleRows[cursorRow] : nil
            let column = row.map { ReadableLine.offset(ofColumn: snapshot.cursor.col, widths: $0.cellWidths, in: $0.text) } ?? 0
            cursorOffset = (starts.isEmpty ? 0 : starts[cursorRow]) + (lines.isEmpty ? 0 : min(column, lines[cursorRow].length))
        }
    }

    private var screen: Screen { Screen(terminalStateSnapshot()) }

    override func isAccessibilityElement() -> Bool { true }
    override func accessibilityRole() -> NSAccessibility.Role? { .textArea }
    override func accessibilityRoleDescription() -> String? { "terminal" }
    override func accessibilityValue() -> Any? { screen.text as String }
    override func accessibilityNumberOfCharacters() -> Int { screen.text.length }
    override func accessibilityVisibleCharacterRange() -> NSRange { NSRange(location: 0, length: screen.text.length) }
    override func accessibilityInsertionPointLineNumber() -> Int { screen.cursorRow }
    override func accessibilitySelectedTextRange() -> NSRange { NSRange(location: screen.cursorOffset, length: 0) }

    override func accessibilityLine(for index: Int) -> Int {
        screen.starts.lastIndex { $0 <= index } ?? 0
    }

    override func accessibilityRange(forLine line: Int) -> NSRange {
        let screen = self.screen
        guard screen.lines.indices.contains(line) else { return NSRange(location: NSNotFound, length: 0) }
        return NSRange(location: screen.starts[line], length: screen.lines[line].length)
    }

    override func accessibilityString(for range: NSRange) -> String? {
        let text = screen.text
        guard range.location != NSNotFound, NSMaxRange(range) <= text.length else { return nil }
        return text.substring(with: range)
    }
}
