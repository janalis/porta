/// A notification posted through macOS's scripting, for a build macOS refuses its own: without a
/// Developer ID, `UNUserNotificationCenter` turns Porta down (UNErrorDomain 1), while `display
/// notification` is allowed to any application. It shows under Script Editor's name.
public enum ScriptedNotification {
    public static let executable = "/usr/bin/osascript"

    /// osascript's arguments: the title and the text are the script's own arguments, never part of
    /// its source, so no quote or backslash in a run's name can change what the script does. `--`
    /// ends osascript's options: a title that starts with `-e` (a project folder can be named so)
    /// would otherwise be compiled as more script.
    public static func arguments(title: String, body: String) -> [String] {
        [
            "-e", "on run argv",
            "-e", "display notification (item 2 of argv) with title (item 1 of argv)",
            "-e", "end run",
            "--", title, body,
        ]
    }
}
