using System;
using System.ComponentModel;
using System.IO;
using System.Text;
using System.Windows;
using System.Windows.Media;
using System.Windows.Threading;
using Microsoft.Terminal.Wpf;

namespace Porta.Desktop.App
{
    /// <summary>One window running one Porta.</summary>
    public sealed class ProjectWindow : Window
    {
        private static readonly TimeSpan AnswerTime = TimeSpan.FromSeconds(4);
        private static readonly TimeSpan ShutdownTime = TimeSpan.FromSeconds(15);
        private static readonly string PlacementFile = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Porta", "window.txt");

        private readonly string token = LaunchPlan.NewToken();
        private CloseFlow flow = new CloseFlow();
        private readonly DispatcherTimer answer = new DispatcherTimer { Interval = AnswerTime };
        private bool exited;
        private bool ready;
        private string? name;
        private readonly string runtime;
        private readonly string architecture;
        private RelayConnection connection;
        private readonly TerminalControl terminal = new TerminalControl();

        public ProjectWindow(string runtime, string architecture, string? project)
        {
            Root = project;
            this.runtime = runtime;
            this.architecture = architecture;
            Title = "Porta";
            Background = Brushes.Black;
            RestorePlacement();

            connection = Connect();
            answer.Tick += (_, _) => AnswerTimedOut();
            Microsoft.Win32.SystemEvents.UserPreferenceChanged += (_, _) => Dispatcher.Invoke(SendAppearance);
            Content = terminal;
            Loaded += (_, _) =>
            {
                var settings = ReadSettings();
                terminal.SetTheme(Theme.Dark, settings.FontFamily ?? "Cascadia Mono", (short)(settings.FontSize ?? 11));
                terminal.Connection = connection;
                terminal.Focus();
            };
        }

        /// <summary>The project, as WSL names it.</summary>
        public string? Root { get; private set; }

        protected override void OnClosing(CancelEventArgs e)
        {
            base.OnClosing(e);
            if (exited)
            {
                SavePlacement();
                return;
            }

            switch (flow.CloseRequested())
            {
                case CloseStep.CloseNow:
                    exited = true;
                    connection.Close();
                    SavePlacement();
                    break;
                case CloseStep.AskPorta:
                    e.Cancel = true;
                    connection.WriteInput(DesktopMessage.CloseRequest(token));
                    answer.Interval = AnswerTime;
                    answer.Start();
                    break;
                default:
                    e.Cancel = true;
                    break;
            }
        }

        private void Received(int code, string payload)
        {
            if (code == OscFilter.Clipboard)
            {
                CopyToClipboard(payload);
                return;
            }

            var (kind, root) = DesktopMessage.Parse(payload, token);
            if (kind == MessageKind.Ready)
            {
                Root = root;
                name = root.Substring(root.LastIndexOf('/') + 1) + " — Porta";
                Title = name;
                ready = true;
                SendAppearance();
                RememberRecent(root);
            }

            if (kind == MessageKind.State && name != null)
            {
                // A mark in the title: closing this window would lose work.
                Title = root == "0" ? name : "• " + name;
            }

            var step = flow.Received(kind);
            if (step == CloseStep.StopWaiting)
            {
                answer.Stop();
            }

            if (step == CloseStep.WaitForExit)
            {
                answer.Stop();
                answer.Interval = ShutdownTime;
                answer.Start();
            }
        }

        private static void CopyToClipboard(string payload)
        {
            var data = payload.Substring(payload.IndexOf(';') + 1);
            try
            {
                Clipboard.SetText(Encoding.UTF8.GetString(Convert.FromBase64String(data)));
            }
            catch (FormatException)
            {
                // Not text Porta wrote.
            }
            catch (System.Runtime.InteropServices.ExternalException)
            {
                // Another application holds the clipboard: this copy is lost, the session is not.
            }
        }

        private void AnswerTimedOut()
        {
            answer.Stop();
            if (flow.AnswerTimedOut() != CloseStep.OfferForceQuit)
            {
                return;
            }

            var choice = MessageBox.Show(
                this,
                "Porta is not responding. Force quitting loses any unsaved changes in this window.\n\nForce quit?",
                "Porta",
                MessageBoxButton.YesNo,
                MessageBoxImage.Warning);
            if (choice == MessageBoxResult.Yes)
            {
                exited = true;
                connection.Close();
                Close();
            }
            else if (flow.IsClosing)
            {
                answer.Start();
            }
        }

        private RelayConnection Connect()
        {
            var relay = new RelayConnection(runtime, architecture, Root, token);
            relay.PrivateSequence += (code, payload) => Dispatcher.Invoke(() => Received(code, payload));
            relay.Exited += status => Dispatcher.Invoke(() => PortaExited(status));
            return relay;
        }

        /// <summary>A file or folder for this window's project, from Explorer or a second launch.</summary>
        public void OpenPath(string path)
        {
            if (ready)
            {
                connection.WriteInput(DesktopMessage.OpenRequest(token, path));
            }

            Activate();
        }

        private void SendAppearance()
        {
            if (!ready)
            {
                return;
            }

            using var personalize = Microsoft.Win32.Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize");
            var light = personalize?.GetValue("AppsUseLightTheme") is int value && value == 1;
            connection.WriteInput(DesktopMessage.Appearance(token, dark: !light));
        }

        /// <summary>The project among Windows' recent items, as Explorer and the Start menu list them.</summary>
        private static void RememberRecent(string root)
        {
            System.Threading.Tasks.Task.Run(() =>
            {
                var windows = WindowRegistry.WindowsPath(root);
                if (windows != null)
                {
                    SHAddToRecentDocs(ShardPathW, windows);
                }
            });
        }

        private const uint ShardPathW = 3;

        [System.Runtime.InteropServices.DllImport("shell32.dll", CharSet = System.Runtime.InteropServices.CharSet.Unicode)]
        private static extern void SHAddToRecentDocs(uint flags, string path);

        private void PortaExited(int status)
        {
            exited = true;
            ready = false;
            answer.Stop();
            if (flow.ClosesWhenPortaExits(status))
            {
                Close();
                return;
            }

            Title = "Porta — stopped";
            var choice = MessageBox.Show(
                this,
                "Porta stopped. What it printed is in the window, and the project's .porta/error.log may say more.\n\nReopen it?\n\nYes reopens it here, No shows the log, Cancel closes the window.",
                "Porta stopped",
                MessageBoxButton.YesNoCancel,
                MessageBoxImage.Warning);
            switch (choice)
            {
                case MessageBoxResult.Yes:
                    Reopen();
                    break;
                case MessageBoxResult.No:
                    ShowLog();
                    break;
                default:
                    Close();
                    break;
            }
        }

        /// <summary>The same project, started again in this window.</summary>
        private void Reopen()
        {
            exited = false;
            flow = new CloseFlow();
            Title = "Porta";
            connection = Connect();
            terminal.Connection = connection;
        }

        private void ShowLog()
        {
            var windows = Root == null ? null : WindowRegistry.WindowsPath(Root + "/.porta/error.log");
            if (windows != null)
            {
                // Shown in its folder, never launched: the project decides what that path is.
                System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(
                    System.IO.Path.Combine(System.Environment.GetFolderPath(System.Environment.SpecialFolder.Windows), "explorer.exe"),
                    "/select,\"" + windows + "\""));
            }

            PortaExited(1);
        }

        private static DesktopSettings ReadSettings()
        {
            var file = Path.Combine(Path.GetDirectoryName(PlacementFile)!, "desktop.json");
            return DesktopSettings.Parse(File.Exists(file) ? File.ReadAllText(file) : null);
        }

        private void RestorePlacement()
        {
            Width = 1100;
            Height = 720;
            if (!File.Exists(PlacementFile))
            {
                return;
            }

            var saved = File.ReadAllText(PlacementFile).Split(',');
            if (saved.Length == 3 && double.TryParse(saved[0], out var width) && double.TryParse(saved[1], out var height))
            {
                Width = width;
                Height = height;
                WindowState = saved[2] == "Maximized" ? WindowState.Maximized : WindowState.Normal;
            }
        }

        private void SavePlacement()
        {
            Directory.CreateDirectory(Path.GetDirectoryName(PlacementFile)!);
            File.WriteAllText(PlacementFile, $"{RestoreBounds.Width},{RestoreBounds.Height},{WindowState}");
        }
    }
}
