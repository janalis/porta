import Foundation

/// How Porta is started inside a window: everything about it that can be decided without a window.
public struct LaunchPlan: Equatable {
    public let executable: String
    public let arguments: [String]
    public let environment: [String]
    public let workingDirectory: String

    /// Inherited variables that would change what the bundled PHP loads or how the IDE reads its terminal.
    private static let dropped: Set<String> = ["PHPRC", "PHP_INI_SCAN_DIR", "WT_SESSION", "TERM_PROGRAM", "TERM_PROGRAM_VERSION", "TMUX", "TMUX_PANE"]

    public init(runtime: String, project: String?, inherited: [String: String], token: String, home: String) {
        executable = runtime + "/php"
        arguments = ["-n", "-d", "memory_limit=-1", "-d", "display_errors=0", runtime + "/porta.phar"] + (project.map { [$0] } ?? [])
        workingDirectory = project ?? home

        var variables = inherited.filter { name, _ in
            !Self.dropped.contains(name) && !name.hasPrefix("DYLD_") && !name.hasPrefix("LD_")
        }
        variables["PORTA_DESKTOP"] = "1"
        variables["PORTA_DESKTOP_TOKEN"] = token
        variables["PORTA_DESKTOP_MENU"] = "system"
        variables["TERM"] = "xterm-256color"
        variables["COLORTERM"] = "truecolor"
        environment = variables.map { "\($0.key)=\($0.value)" }.sorted()
    }

    /// 128 bits of randomness, as the lowercase hex Porta insists on.
    public static func newToken() -> String {
        var generator = SystemRandomNumberGenerator()
        return (0..<16).map { _ in String(format: "%02x", UInt8.random(in: 0...255, using: &generator)) }.joined()
    }
}
