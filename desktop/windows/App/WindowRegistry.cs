using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Windows;
using Microsoft.Win32;

namespace Porta.Desktop.App
{
    /// <summary>One window per project, as WSL names the project.</summary>
    public sealed class WindowRegistry
    {
        private readonly List<ProjectWindow> windows = new List<ProjectWindow>();

        public async void Open(string? project) => await OpenAsync(project);

        private async Task OpenAsync(string? project)
        {
            if (project == null && windows.Count > 0)
            {
                windows.Last().Activate();
                return;
            }

            var runtimeFolder = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "runtime");
            var runtime = await Task.Run(() => WslPath(runtimeFolder));
            var root = project == null ? null : await Task.Run(() => WslPath(project));
            var architecture = LaunchPlan.Architecture(await Task.Run(() => Wsl("uname", "-m") ?? ""));
            if (runtime == null || architecture == null || (project != null && root == null))
            {
                await ShowNeeds(project, answered: runtime != null && architecture != null);
                return;
            }

            var existing = windows.FirstOrDefault(window => root != null && window.Root == root);
            if (existing != null)
            {
                existing.Activate();
                return;
            }

            // A file, or a folder inside a project already open, goes to that window.
            var holder = windows.FirstOrDefault(window => root != null && window.Root != null && root.StartsWith(window.Root + "/", StringComparison.Ordinal));
            if (holder != null)
            {
                holder.OpenPath(root!);
                return;
            }

            var opened = new ProjectWindow(runtime, architecture, root);
            opened.Dropped += Open;
            opened.FontChosen += () =>
            {
                foreach (var window in windows)
                {
                    window.ApplySavedFont();
                }
            };
            opened.Closed += (_, _) => windows.Remove(opened);
            windows.Add(opened);
            opened.Show();
        }

        /// <summary>What is missing, and the one step that helps; the application ends with it when it is alone.</summary>
        /// <param name="answered">whether Linux answered, and only the project's folder was out of its reach</param>
        private async Task ShowNeeds(string? project, bool answered)
        {
            // Linux that answered is installed, whatever the registry says: only the folder was missing.
            var need = answered ? WslNeed.Start : WslCheck.Of(WslInstalled(), Distributions());
            var reason = need != WslNeed.Start ? ""
                : answered ? $"WSL cannot reach {project}."
                : WslCheck.Reason(await Task.Run(() => WslSaid("uname", "-m")));
            var needs = new PermissionsWindow(need, reason, () => Distributions() > 0 && LaunchPlan.Architecture(Wsl("uname", "-m") ?? "") != null);
            var retrying = false;
            needs.Retry += async () =>
            {
                // The window stays until the next one is up: the application ends with its last window.
                await OpenAsync(project);
                retrying = true;
                needs.Close();
            };
            if (windows.Count == 0)
            {
                needs.Closed += (_, _) =>
                {
                    if (!retrying)
                    {
                        Application.Current.Shutdown();
                    }
                };
            }

            needs.Show();
            needs.Activate();
        }

        /// <summary>Whether WSL is installed: its service, or the one WSL had before it moved to the Store.</summary>
        private static bool WslInstalled()
        {
            using var services = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services");
            using var service = services?.OpenSubKey("WSLService");
            using var legacy = services?.OpenSubKey("LxssManager");
            return service != null || legacy != null;
        }

        /// <summary>The user's distributions: one key each, named by its GUID.</summary>
        private static int Distributions()
        {
            using var lxss = Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Lxss");
            return lxss?.GetSubKeyNames().Count(name => name.StartsWith("{", StringComparison.Ordinal)) ?? 0;
        }

        /// <summary>A path inside WSL as Windows spells it; null when WSL cannot answer.</summary>
        public static string? WindowsPath(string path) => Wsl("wslpath", "-w", path);

        /// <summary>The path as WSL spells it; null when WSL cannot answer.</summary>
        private static string? WslPath(string path)
        {
            var answer = Wsl("wslpath", "-a", "-u", path);
            return answer != null && answer.StartsWith("/") ? answer : null;
        }

        /// <summary>What a command inside WSL printed; null when WSL cannot answer in time.</summary>
        private static string? Wsl(params string[] command)
        {
            var (exit, output) = Run(command);
            return exit == 0 ? output.Trim() : null;
        }

        /// <summary>Everything wsl.exe said running a command, its own errors included.</summary>
        private static string WslSaid(params string[] command) => Run(command).Output;

        private static (int? Exit, string Output) Run(string[] command)
        {
            try
            {
                var start = new ProcessStartInfo(LaunchPlan.Executable, LaunchPlan.CommandLine(new[] { "--exec" }.Concat(command)))
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
                if (!process.WaitForExit(20000))
                {
                    process.Kill();
                    return (null, "WSL did not answer within 20 seconds.");
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
