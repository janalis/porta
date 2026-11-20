/// What a change in the font panel means for desktop.json.
public enum FontPick: Equatable {
    /// Nothing was picked: the panel also sends a change for a click beside its list.
    case unchanged
    /// A font whose letters are not all as wide: a terminal's grid cannot use it.
    case refused
    /// The system's own monospaced font, by its private name: an empty desktop.json gives it already.
    case systemDefault
    /// A family to write.
    case chosen(family: String, size: Double)

    public static func judge(currentName: String, currentSize: Double, chosenName: String, chosenFamily: String?, chosenSize: Double, fixedPitch: Bool) -> FontPick {
        if chosenName == currentName && chosenSize == currentSize {
            return .unchanged
        }
        guard let family = chosenFamily, !family.hasPrefix(".") else {
            return .systemDefault
        }
        return fixedPitch ? .chosen(family: family, size: chosenSize) : .refused
    }
}
