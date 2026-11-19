/// A screen row as a screen reader should get it: borders and shading are drawing, not words,
/// and a reader would spell out every one. Each becomes one space, so a column on screen is
/// still the same offset in the text; the blanks at the end go.
public enum ReadableLine {
    public static func of(_ row: String) -> String {
        var scalars = String.UnicodeScalarView(row.unicodeScalars.map { (0x2500...0x259F).contains($0.value) ? " " : $0 })
        while let last = scalars.last, last == " " || last == "\t" {
            scalars.removeLast()
        }
        return String(scalars)
    }
}
