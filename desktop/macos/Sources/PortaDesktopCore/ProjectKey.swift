import Foundation

/// Two spellings of one folder are one project: one window.
public enum ProjectKey {
    public static func of(_ path: String) -> String {
        URL(fileURLWithPath: path).resolvingSymlinksInPath().standardizedFileURL.path
    }
}
