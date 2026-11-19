import Foundation

/// Two spellings of one folder are one project: one window.
public enum ProjectKey {
    public static func of(_ path: String) -> String {
        URL(fileURLWithPath: path).resolvingSymlinksInPath().standardizedFileURL.path
    }

    /// Whether $path is the project at $key, or inside it.
    public static func contains(_ key: String, _ path: String) -> Bool {
        let inside = of(path)
        return inside == key || inside.hasPrefix(key.hasSuffix("/") ? key : key + "/")
    }
}
