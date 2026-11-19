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
                MessageBox.Show(
                    "Porta on Windows runs inside the Windows Subsystem for Linux.\n\nInstall it from an administrator terminal with:\n\n    wsl --install",
                    "Porta needs WSL",
                    MessageBoxButton.OK,
                    MessageBoxImage.Information);
                if (windows.Count == 0)
                {
                    Application.Current.Shutdown();
                }

                return;
            }

            var existing = windows.FirstOrDefault(window => root != null && window.Root == root);
            if (existing != null)
            {
                existing.Activate();
                return;
            }

            var opened = new ProjectWindow(runtime, architecture, root);
            opened.Closed += (_, _) => windows.Remove(opened);
            windows.Add(opened);
            opened.Show();
        }

        /// <summary>The path as WSL spells it; null when WSL cannot answer.</summary>
        private static string? WslPath(string path)
        {
            var answer = Wsl("wslpath", "-a", "-u", path);
            return answer != null && answer.StartsWith("/") ? answer : null;
        }

        /// <summary>What a command inside WSL printed; null when WSL cannot answer.</summary>
        private static string? Wsl(params string[] command)
        {
            try
            {
                var start = new ProcessStartInfo(LaunchPlan.Executable, LaunchPlan.CommandLine(new[] { "--exec" }.Concat(command)))
                {
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    RedirectStandardOutput = true,
                    StandardOutputEncoding = Encoding.UTF8,
                };
                using var process = Process.Start(start)!;
                var answer = process.StandardOutput.ReadToEnd().Trim();
                process.WaitForExit();
                return process.ExitCode == 0 ? answer : null;
            }
            catch (System.ComponentModel.Win32Exception)
            {
                return null;
            }
        }
    }
}
