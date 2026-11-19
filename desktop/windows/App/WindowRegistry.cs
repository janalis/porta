using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Windows;

namespace Porta.Desktop.App
{
    /// <summary>One window per project, as WSL names the project.</summary>
    public sealed class WindowRegistry
    {
        private readonly List<ProjectWindow> windows = new List<ProjectWindow>();

        public async void Open(string? project)
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
                // What is missing, and the button that installs it; the application ends with it when it is alone.
                var needs = new PermissionsWindow();
                if (windows.Count == 0)
                {
                    needs.Closed += (_, _) => Application.Current.Shutdown();
                }

                needs.Show();
                needs.Activate();

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
            try
            {
                var start = new ProcessStartInfo(LaunchPlan.Executable, LaunchPlan.CommandLine(new[] { "--exec" }.Concat(command)))
                {
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    // Without WSL, wsl.exe offers to install it and waits for a key: give it none.
                    RedirectStandardInput = true,
                    RedirectStandardOutput = true,
                    StandardOutputEncoding = Encoding.UTF8,
                };
                using var process = Process.Start(start)!;
                process.StandardInput.Close();
                var answer = process.StandardOutput.ReadToEndAsync();
                if (!process.WaitForExit(20000))
                {
                    process.Kill();
                    return null;
                }

                return process.ExitCode == 0 ? answer.Result.Trim() : null;
            }
            catch (System.ComponentModel.Win32Exception)
            {
                return null;
            }
        }
    }
}
