/// A screen row as a screen reader should get it: borders and shading are drawing, not words,
/// and a reader would spell out every one. Each becomes one space, character for character,
/// so the text keeps the row's own offsets; the blanks at the end go.
public enum ReadableLine {
    public static func of(_ row: String) -> String {
        var scalars = String.UnicodeScalarView(row.unicodeScalars.map { (0x2500...0x259F).contains($0.value) ? " " : $0 })
        while let last = scalars.last, last == " " || last == "\t" {
            scalars.removeLast()
        }
        return String(scalars)
    }

    /// Where a screen column falls in the row's text, in UTF-16 units as accessibility counts:
    /// a wide character fills two columns (its second cell has width 0) and is one character,
    /// an emoji is two units. `widths` is the width of each cell, as the terminal reports it.
    public static func offset(ofColumn column: Int, widths: [Int], in text: String) -> Int {
        let drawn = widths.prefix(max(column, 0)).filter { $0 > 0 }.count
        return text.prefix(drawn).utf16.count
    }
}
