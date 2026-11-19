import Foundation

/// The window's own settings, from `desktop.json`. Anything unusable reads as not set.
public struct DesktopSettings: Decodable, Equatable {
    private static let sizes = 6.0...72.0

    public let fontFamily: String?
    public let fontSize: Double?

    public init(fontFamily: String? = nil, fontSize: Double? = nil) {
        self.fontFamily = fontFamily?.isEmpty == false ? fontFamily : nil
        self.fontSize = fontSize.flatMap { Self.sizes.contains($0) ? $0 : nil }
    }

    public init(from decoder: Decoder) throws {
        let values = try decoder.container(keyedBy: CodingKeys.self)
        self.init(
            fontFamily: try? values.decode(String.self, forKey: .fontFamily),
            fontSize: try? values.decode(Double.self, forKey: .fontSize)
        )
    }

    private enum CodingKeys: String, CodingKey {
        case fontFamily, fontSize
    }

    public static func parse(_ data: Data?) -> DesktopSettings {
        data.flatMap { try? JSONDecoder().decode(DesktopSettings.self, from: $0) } ?? DesktopSettings()
    }

    /// A chosen size as the file holds it: whole points, within what a window reads (contract/font-choice.json).
    public static func writtenSize(_ chosen: Double) -> Double {
        min(sizes.upperBound, max(sizes.lowerBound, chosen.rounded(.toNearestOrAwayFromZero)))
    }

    /// The file with a font chosen in the window: the two keys set, anything else in it kept.
    public static func written(_ existing: Data?, fontFamily: String, fontSize: Double) -> Data {
        var object = existing.flatMap { try? JSONSerialization.jsonObject(with: $0) as? [String: Any] } ?? [:]
        object["fontFamily"] = fontFamily
        object["fontSize"] = writtenSize(fontSize)
        return (try? JSONSerialization.data(withJSONObject: object, options: [.prettyPrinted, .sortedKeys])) ?? Data()
    }

    /// `$PORTA_HOME/.porta/desktop.json`, else the same folder under the home directory.
    public static func path(environment: [String: String], home: String) -> String {
        (environment["PORTA_HOME"].flatMap { $0.isEmpty ? nil : $0 } ?? home) + "/.porta/desktop.json"
    }
}
