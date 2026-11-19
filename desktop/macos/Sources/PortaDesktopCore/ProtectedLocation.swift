/// The folders macOS guards with its own permission (Files and Folders): a project inside one
/// is readable only once the user has allowed Porta, which the system asks on first access.
public enum ProtectedLocation {
    /// The guarded folder `path` lies in, by the name System Settings gives it; nil when none does.
    public static func of(_ path: String, home: String) -> String? {
        let folders = [
            ("Desktop", home + "/Desktop"),
            ("Documents", home + "/Documents"),
            ("Downloads", home + "/Downloads"),
            ("iCloud Drive", home + "/Library/Mobile Documents"),
        ]
        for (name, folder) in folders where path == folder || path.hasPrefix(folder + "/") {
            return name
        }
        // Removable and network volumes are guarded too.
        let parts = path.split(separator: "/", omittingEmptySubsequences: true)
        if parts.count >= 2, parts[0] == "Volumes" {
            return "Removable Volumes"
        }
        return nil
    }
}
