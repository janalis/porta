using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Linq;
using System.Text;
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
            var (exit, output) = Run(new[] { "--exec" }.Concat(command), TimeSpan.FromSeconds(20));
            return exit == 0 ? output.Trim() : null;
        }

        /// <summary>Everything wsl.exe said running a command inside Linux, its own errors included.</summary>
        public static string Said(params string[] command) => Run(new[] { "--exec" }.Concat(command), TimeSpan.FromSeconds(20)).Output;

        /// <summary>wsl.exe itself, with no window: its exit code (null when it did not end in time) and all it said.</summary>
        public static (int? Exit, string Output) Run(IEnumerable<string> arguments, TimeSpan patience)
        {
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
                var answer = process.StandardOutput.ReadToEndAsync();
                var errors = process.StandardError.ReadToEndAsync();
                if (!process.WaitForExit((int)patience.TotalMilliseconds))
                {
                    process.Kill();
                    return (null, $"WSL did not answer within {patience.TotalSeconds} seconds.");
                }

                return (process.ExitCode, answer.Result + "\n" + errors.Result);
            }
            catch (System.ComponentModel.Win32Exception exception)
            {
                return (null, exception.Message);
            }
        }
    }
}
