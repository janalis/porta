using System.Linq;

namespace Porta.Desktop
{
    /// <summary>What stops Porta from running inside WSL, so the window can offer the one step that helps.</summary>
    public enum WslNeed
    {
        /// <summary>No Windows Subsystem for Linux at all: it is installed with administrator rights.</summary>
        Wsl,

        /// <summary>WSL without a Linux distribution: what a first <c>wsl --install</c> often leaves.</summary>
        Distribution,

        /// <summary>A distribution that does not answer: WSL's own message says why.</summary>
        Start,
    }

    public static class WslCheck
    {
        /// <param name="installed">whether WSL's service exists (WSLService, or LxssManager before it)</param>
        /// <param name="distributions">how many distributions the user has registered</param>
        public static WslNeed Of(bool installed, int distributions) =>
            !installed ? WslNeed.Wsl : distributions == 0 ? WslNeed.Distribution : WslNeed.Start;

        /// <summary>
        /// What wsl.exe said, fit to show in a few lines: it may write UTF-16 read as UTF-8 (a NUL after
        /// every letter), with blank lines between. The last lines are kept: its progress comes first,
        /// and its error and the error code, which is what a search finds, last.
        /// </summary>
        public static string Reason(string output)
        {
            var lines = output.Replace("\0", "").Replace("\r", "").Split('\n')
                .Select(line => line.Trim())
                .Where(line => line.Length > 0)
                .ToList();

            return string.Join("\n", lines.Skip(System.Math.Max(0, lines.Count - MaxLines)));
        }

        private const int MaxLines = 6;
    }
}
