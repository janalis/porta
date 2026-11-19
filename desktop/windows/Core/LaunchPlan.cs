using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;

namespace Porta.Desktop
{
    /// <summary>How Porta is started inside WSL, through the relay that gives it a real terminal.</summary>
    public static class LaunchPlan
    {
        /// <summary>Always System32's: by bare name, Windows would look in the working directory first.</summary>
        public static string Executable { get; } = Path.Combine(System.Environment.GetFolderPath(System.Environment.SpecialFolder.System), "wsl.exe");

        public static IReadOnlyDictionary<string, string> Environment(string token) => new Dictionary<string, string>
        {
            ["PORTA_DESKTOP"] = "1",
            ["PORTA_DESKTOP_TOKEN"] = token,
            ["TERM"] = "xterm-256color",
            ["COLORTERM"] = "truecolor",
            ["WSLENV"] = "PORTA_DESKTOP/u:PORTA_DESKTOP_TOKEN/u:TERM/u:COLORTERM/u",
        };

        /// <summary>The folder of the bundled PHP for what `uname -m` said; null when Porta ships none.</summary>
        public static string? Architecture(string uname)
        {
            // wsl.exe may print its own warnings around the answer.
            var match = System.Text.RegularExpressions.Regex.Match(uname, @"\b(x86_64|aarch64)\b");
            return match.Success ? match.Groups[1].Value : null;
        }

        /// <param name="runtime">the runtime folder, as WSL spells it</param>
        /// <param name="architecture">the distribution's, from <see cref="Architecture"/></param>
        /// <param name="project">the project, as WSL spells it; null when an icon click names none</param>
        public static IReadOnlyList<string> Arguments(string runtime, string architecture, string? project, int columns, int rows)
        {
            var php = runtime + "/" + architecture + "/php";
            var arguments = new List<string>
            {
                "--cd", "~", "--exec",
                php, "-n", runtime + "/wsl-relay.php", columns.ToString(), rows.ToString(),
                php, "-n", "-d", "memory_limit=-1", "-d", "display_errors=0", runtime + "/porta.phar",
            };
            if (project != null)
            {
                arguments.Add(project);
            }

            return arguments;
        }

        /// <summary>One command line, quoted the way Windows programs split it again.</summary>
        public static string CommandLine(IEnumerable<string> arguments) => string.Join(" ", arguments.Select(Quote));

        public static string NewToken()
        {
            var bytes = new byte[16];
            using (var random = RandomNumberGenerator.Create())
            {
                random.GetBytes(bytes);
            }

            return string.Concat(bytes.Select(value => value.ToString("x2")));
        }

        private static string Quote(string argument)
        {
            if (argument.Length > 0 && argument.IndexOfAny(new[] { ' ', '\t', '"' }) < 0)
            {
                return argument;
            }

            var quoted = new StringBuilder("\"");
            var slashes = 0;
            foreach (var character in argument)
            {
                if (character == '\\')
                {
                    slashes++;
                    continue;
                }

                quoted.Append('\\', character == '"' ? slashes * 2 + 1 : slashes);
                slashes = 0;
                quoted.Append(character);
            }

            return quoted.Append('\\', slashes * 2).Append('"').ToString();
        }
    }

    /// <summary>The relay's framing: see desktop/runtime/wsl-relay.php.</summary>
    public static class RelayFrame
    {
        public static byte[] Input(byte[] bytes)
        {
            var frame = new byte[5 + bytes.Length];
            frame[1] = (byte)(bytes.Length >> 24);
            frame[2] = (byte)(bytes.Length >> 16);
            frame[3] = (byte)(bytes.Length >> 8);
            frame[4] = (byte)bytes.Length;
            Buffer.BlockCopy(bytes, 0, frame, 5, bytes.Length);
            return frame;
        }

        public static byte[] Resize(int columns, int rows) => new byte[]
        {
            1, (byte)(columns >> 8), (byte)columns, (byte)(rows >> 8), (byte)rows,
        };
    }
}
