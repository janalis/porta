using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Threading.Tasks;
using System.Windows;

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
            var uname = await Task.Run(() => WslShell.Exec("uname", "-m"));
            var architecture = uname == null ? null : LaunchPlan.Architecture(uname);
            if (runtime == null || architecture == null || (project != null && root == null))
            {
                await ShowNeeds(project, Reason(project, runtimeFolder, runtime, uname, architecture));
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

        /// <summary>Why Linux, which answered, cannot run Porta; null when it did not answer, and WSL is the one to ask.</summary>
        private static string? Reason(string? project, string runtimeFolder, string? runtime, string? uname, string? architecture) =>
            uname == null ? null
            : architecture == null ? $"Porta brings PHP for x86_64 and aarch64 Linux; this distribution is {uname}."
            : runtime == null ? $"WSL cannot reach Porta's own files in {runtimeFolder}."
            : $"WSL cannot reach {project}.";

        /// <summary>What is missing, and the one step that helps; the application ends with it when it is alone.</summary>
        /// <param name="answered">why Linux, which answered, cannot run Porta; null when it did not answer</param>
        private async Task ShowNeeds(string? project, string? answered)
        {
            // Linux that answered is installed, whatever the registry says.
            var need = answered != null ? WslNeed.Start : WslCheck.Of(WslShell.Installed(), WslShell.Distributions());
            var reason = answered
                ?? (need == WslNeed.Start ? WslCheck.Reason(await Task.Run(() => WslShell.Said("uname", "-m"))) : "");
            var needs = new PermissionsWindow(need, reason);
            var retrying = false;
            needs.Retry += async () =>
            {
                // Before the wait: closed while the check runs, the window must not end the application under it.
                retrying = true;
                // The window stays until the next one is up: the application ends with its last window.
                await OpenAsync(project);
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

        /// <summary>A path inside WSL as Windows spells it; null when WSL cannot answer.</summary>
        public static string? WindowsPath(string path) => WslShell.Exec("wslpath", "-w", path);

        /// <summary>The path as WSL spells it; null when WSL cannot answer.</summary>
        private static string? WslPath(string path)
        {
            var answer = WslShell.Exec("wslpath", "-a", "-u", path);
            return answer != null && answer.StartsWith("/") ? answer : null;
        }
    }
}
