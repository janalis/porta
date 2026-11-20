using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using Microsoft.Win32;

namespace Porta.Desktop.App
{
    /// <summary>wsl.exe, asked from Windows: what is installed, and what a command inside Linux says.</summary>
    public static class WslShell
    {
        /// <summary>Whether WSL is installed: its service, or the one WSL had before it moved to the Store.</summary>
        public static bool Installed()
        {
            using var services = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services");
            using var service = services?.OpenSubKey("WSLService");
            using var legacy = services?.OpenSubKey("LxssManager");
            return service != null || legacy != null;
        }

        /// <summary>The user's distributions: one key each, named by its GUID.</summary>
        public static int Distributions()
        {
            using var lxss = Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Lxss");
            return lxss?.GetSubKeyNames().Count(name => name.StartsWith("{", StringComparison.Ordinal)) ?? 0;
        }

        /// <summary>What a command inside WSL printed; null when WSL cannot answer in time.</summary>
        public static string? Exec(params string[] command)
        {
            // Its standard output alone: wsl.exe warns on standard error (a proxy it cannot mirror, a
            // PATH entry it cannot translate), and a warning glued to a path breaks the path.
            var said = Run(new[] { "--exec" }.Concat(command), TimeSpan.FromSeconds(20));
            return said.Exit == 0 ? said.Output.Trim() : null;
        }

        /// <summary>Everything wsl.exe said running a command inside Linux, its own errors included.</summary>
        public static string Said(params string[] command) => Run(new[] { "--exec" }.Concat(command), TimeSpan.FromSeconds(20)).All;

        /// <summary>wsl.exe itself, with no window, given <paramref name="patience"/> to end and finish writing.</summary>
        public static Answer Run(IEnumerable<string> arguments, TimeSpan patience)
        {
            var deadline = DateTime.UtcNow + patience;
            try
            {
                var start = new ProcessStartInfo(LaunchPlan.Executable, LaunchPlan.CommandLine(arguments))
                {
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    // Without WSL, wsl.exe offers to install it and waits for a key: give it none.
                    RedirectStandardInput = true,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    StandardOutputEncoding = Encoding.UTF8,
                    StandardErrorEncoding = Encoding.UTF8,
                };
                // wsl.exe's own messages are UTF-16 unless it is asked for UTF-8.
                start.EnvironmentVariables["WSL_UTF8"] = "1";
                using var process = Process.Start(start)!;
                process.StandardInput.Close();
                var output = process.StandardOutput.ReadToEndAsync();
                var errors = process.StandardError.ReadToEndAsync();
                var late = $"WSL did not answer within {patience.TotalSeconds} seconds.";
                if (!process.WaitForExit(Remaining(deadline)))
                {
                    try
                    {
                        process.Kill();
                    }
                    catch (InvalidOperationException)
                    {
                        // It ended between the wait and the kill.
                    }

                    return new Answer(null, "", late);
                }

                // A helper wsl.exe started may hold the pipes open after it ended: the limit covers the reading too.
                if (!Task.WaitAll(new Task[] { output, errors }, Remaining(deadline)))
                {
                    return new Answer(null, output.IsCompleted ? output.Result : "", late);
                }

                return new Answer(process.ExitCode, output.Result, errors.Result);
            }
            catch (System.ComponentModel.Win32Exception exception)
            {
                return new Answer(null, "", exception.Message);
            }
        }

        private static int Remaining(DateTime deadline) => (int)Math.Max(0, (deadline - DateTime.UtcNow).TotalMilliseconds);

        /// <summary>How wsl.exe ended (null: it did not, in time), and what it wrote where.</summary>
        public sealed class Answer
        {
            public Answer(int? exit, string output, string errors)
            {
                Exit = exit;
                Output = output;
                Errors = errors;
            }

            public int? Exit { get; }

            public string Output { get; }

            public string Errors { get; }

            /// <summary>Both streams, as a reader of wsl.exe's own messages wants them.</summary>
            public string All => Output + "\n" + Errors;
        }
    }
}
