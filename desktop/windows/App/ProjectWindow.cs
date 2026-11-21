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
        private bool choosing;
        private bool? sentDark;
        private readonly System.Collections.Generic.List<string> pending = new System.Collections.Generic.List<string>();
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
            // A static event: unsubscribed on close, or every closed window would stay alive.
            Microsoft.Win32.SystemEvents.UserPreferenceChanged += PreferenceChanged;
            Closed += (_, _) => Microsoft.Win32.SystemEvents.UserPreferenceChanged -= PreferenceChanged;
            Content = terminal;

            // Files and folders dropped on the window go where opening them would. The terminal is a
            // native child window with no drop target of its own, so the drop reaches this one.
            AllowDrop = true;
            DragOver += (_, e) =>
            {
                e.Effects = e.Data.GetDataPresent(DataFormats.FileDrop) ? DragDropEffects.Copy : DragDropEffects.None;
                e.Handled = true;
            };
            Drop += (_, e) =>
            {
                if (e.Data.GetData(DataFormats.FileDrop) is string[] paths)
                {
                    foreach (var path in paths)
                    {
                        Dropped?.Invoke(path);
                    }
                }

                e.Handled = true;
            };
            SourceInitialized += (_, _) => AddFontToSystemMenu();
            Loaded += (_, _) =>
            {
                ApplySavedFont();
                terminal.Connection = connection;
                terminal.Focus();
            };
        }

        /// <summary>A font was chosen in this window: every window takes it.</summary>
        public event Action? FontChosen;

        /// <summary>A file or folder dropped on the window, as Windows names it.</summary>
        public event Action<string>? Dropped;

        /// <summary>A folder chosen in the picker's dialog, as Windows names it: this window's project.</summary>
        public event Action<string>? FolderChosen;

        /// <summary>Porta's picker is up: no project yet, and a folder sent now is the one it opens.</summary>
        public bool Picking { get; private set; }

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
                Picking = false;
                sentDark = null;
                SendAppearance();
                RememberRecent(root);
                foreach (var path in pending)
                {
                    connection.WriteInput(DesktopMessage.OpenRequest(token, path));
                }

                pending.Clear();
            }

            if (kind == MessageKind.Picking && !ready)
            {
                Picking = true;
            }

            if (kind == MessageKind.ChooseFolder && Picking)
            {
                // Posted, not run here: this runs on the relay's read loop, which a modal dialog would stall.
                Dispatcher.BeginInvoke(new Action(ChooseFolder));
            }

            if (kind == MessageKind.Picked)
            {
                Picking = false;
            }

            if (kind == MessageKind.Notify && !IsActive)
            {
                // A run ended while the user was elsewhere: the taskbar button flashes until they come back.
                Flash();
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
            var relay = new RelayConnection(runtime, architecture, Root, token, SystemIsDark());
            relay.PrivateSequence += (code, payload) => Dispatcher.Invoke(() => Received(code, payload));
            relay.Exited += status => Dispatcher.Invoke(() => PortaExited(status));
            return relay;
        }

        /// <summary>A file or folder for this window's project, from Explorer or a second launch.</summary>
        public void OpenPath(string path)
        {
            if (ready || Picking)
            {
                connection.WriteInput(DesktopMessage.OpenRequest(token, path));
                // Handed its project: the next folder is not this window's to take.
                Picking = false;
            }
            else
            {
                // Still starting: sent once Porta is ready.
                pending.Add(path);
            }

            Activate();
        }

        /// <summary>Light or dark mode is a General preference; the mouse, the wallpaper and the rest are not.</summary>
        private void PreferenceChanged(object sender, Microsoft.Win32.UserPreferenceChangedEventArgs e)
        {
            if (e.Category == Microsoft.Win32.UserPreferenceCategory.General)
            {
                Dispatcher.Invoke(SendAppearance);
            }
        }

        private void SendAppearance()
        {
            if (!ready)
            {
                return;
            }

            var dark = SystemIsDark();
            // Sent when it changes: Porta rebuilds every pane's styles for a theme.
            if (sentDark != dark)
            {
                sentDark = dark;
                connection.WriteInput(DesktopMessage.Appearance(token, dark));
            }
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

        private void Flash()
        {
            var info = new FlashInfo
            {
                Size = (uint)System.Runtime.InteropServices.Marshal.SizeOf<FlashInfo>(),
                Window = new System.Windows.Interop.WindowInteropHelper(this).Handle,
                Flags = FlashTray | FlashUntilForeground,
            };
            FlashWindowEx(ref info);
        }

        private const uint FlashTray = 2;
        private const uint FlashUntilForeground = 12;

        [System.Runtime.InteropServices.StructLayout(System.Runtime.InteropServices.LayoutKind.Sequential)]
        private struct FlashInfo
        {
            public uint Size;
            public IntPtr Window;
            public uint Flags;
            public uint Count;
            public uint Timeout;
        }

        [System.Runtime.InteropServices.DllImport("user32.dll")]
        private static extern bool FlashWindowEx(ref FlashInfo info);

        private const uint ShardPathW = 3;

        [System.Runtime.InteropServices.DllImport("shell32.dll", CharSet = System.Runtime.InteropServices.CharSet.Unicode)]
        private static extern void SHAddToRecentDocs(uint flags, string path);

        private void PortaExited(int status)
        {
            exited = true;
            ready = false;
            Picking = false;
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

        private async void ShowLog()
        {
            // wslpath can take seconds while WSL starts: never on the window's thread.
            var root = Root;
            var windows = root == null ? null : await System.Threading.Tasks.Task.Run(() => WindowRegistry.WindowsPath(root + "/.porta/error.log"));
            if (windows != null)
            {
                // Shown in its folder, never launched: the project decides what that path is.
                System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(
                    System.IO.Path.Combine(System.Environment.GetFolderPath(System.Environment.SpecialFolder.Windows), "explorer.exe"),
                    "/select,\"" + windows + "\""));
            }

            PortaExited(1);
        }

        private static string SettingsFile => Path.Combine(Path.GetDirectoryName(PlacementFile)!, "desktop.json");

        private static DesktopSettings ReadSettings() =>
            DesktopSettings.Parse(File.Exists(SettingsFile) ? File.ReadAllText(SettingsFile) : null);

        /// <summary>The font desktop.json names, after a choice in any window.</summary>
        public void ApplySavedFont()
        {
            var settings = ReadSettings();
            terminal.SetTheme(Theme.Dark, settings.FontFamily ?? DefaultFont, (short)(settings.FontSize ?? DefaultFontSize));
        }

        private const string DefaultFont = "Cascadia Mono";
        private const double DefaultFontSize = 11;
        private const int FontCommand = 0x1000;

        /// <summary>
        /// "Font…" in the window's system menu (Alt+Space, or a right click on the title bar), where
        /// the Windows console keeps its own: this window has no menu bar of its own to hold it.
        /// </summary>
        private void AddFontToSystemMenu()
        {
            var handle = new System.Windows.Interop.WindowInteropHelper(this).Handle;
            var menu = GetSystemMenu(handle, false);
            AppendMenu(menu, MenuSeparator, IntPtr.Zero, null);
            AppendMenu(menu, MenuString, (IntPtr)FontCommand, "Font…");
            System.Windows.Interop.HwndSource.FromHwnd(handle)?.AddHook(SystemMenuChosen);
        }

        private IntPtr SystemMenuChosen(IntPtr window, int message, IntPtr wParam, IntPtr lParam, ref bool handled)
        {
            const int SystemCommand = 0x0112;
            if (message == SystemCommand && (wParam.ToInt64() & 0xFFF0) == FontCommand)
            {
                handled = true;
                ChooseFont();
            }

            return IntPtr.Zero;
        }

        /// <summary>Whether apps use the dark theme; dark when Windows does not say.</summary>
        private static bool SystemIsDark()
        {
            using var personalize = Microsoft.Win32.Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize");
            return !(personalize?.GetValue("AppsUseLightTheme") is int value && value == 1);
        }

        /// <summary>The picker's "Choose a folder…": Windows' folder dialog, owned by this window.</summary>
        private void ChooseFolder()
        {
            // Asked again while the dialog is up, a second one would stack on the first.
            if (choosing)
            {
                return;
            }

            choosing = true;
            using var dialog = new System.Windows.Forms.FolderBrowserDialog { Description = "Open a project", ShowNewFolderButton = false };
            var chosen = dialog.ShowDialog(new Owner(new System.Windows.Interop.WindowInteropHelper(this).Handle)) == System.Windows.Forms.DialogResult.OK;
            choosing = false;
            if (chosen && Picking)
            {
                FolderChosen?.Invoke(dialog.SelectedPath);
            }
        }

        private void ChooseFont()
        {
            var settings = ReadSettings();
            using var dialog = new System.Windows.Forms.FontDialog
            {
                FixedPitchOnly = true,
                ShowEffects = false,
                AllowScriptChange = false,
                MinSize = 6,
                MaxSize = 72,
                Font = new System.Drawing.Font(settings.FontFamily ?? DefaultFont, (float)(settings.FontSize ?? DefaultFontSize)),
            };
            try
            {
                // Owned by this window: modal to it, and never behind it.
                if (dialog.ShowDialog(new Owner(new System.Windows.Interop.WindowInteropHelper(this).Handle)) != System.Windows.Forms.DialogResult.OK)
                {
                    return;
                }
            }
            catch (ArgumentException)
            {
                // The dialog refuses a font that is not TrueType, by throwing: nothing changes.
                return;
            }

            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(SettingsFile)!);
                var existing = File.Exists(SettingsFile) ? File.ReadAllText(SettingsFile) : null;
                // Written beside, then moved over: a reader never sees half a file.
                var staged = SettingsFile + ".new";
                File.WriteAllText(staged, DesktopSettings.Written(existing, dialog.Font.FontFamily.Name, dialog.Font.SizeInPoints));
                if (File.Exists(SettingsFile))
                {
                    File.Replace(staged, SettingsFile, null);
                }
                else
                {
                    File.Move(staged, SettingsFile);
                }
            }
            catch (Exception e) when (e is IOException || e is UnauthorizedAccessException)
            {
                MessageBox.Show(this, SettingsFile + ": " + e.Message, "The font could not be saved", MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }

            FontChosen?.Invoke();
        }

        /// <summary>The window, as the font dialog takes its owner.</summary>
        private sealed class Owner : System.Windows.Forms.IWin32Window
        {
            public Owner(IntPtr handle) => Handle = handle;

            public IntPtr Handle { get; }
        }

        private const uint MenuString = 0;
        private const uint MenuSeparator = 0x800;

        [System.Runtime.InteropServices.DllImport("user32.dll")]
        private static extern IntPtr GetSystemMenu(IntPtr window, bool revert);

        [System.Runtime.InteropServices.DllImport("user32.dll", CharSet = System.Runtime.InteropServices.CharSet.Unicode)]
        private static extern bool AppendMenu(IntPtr menu, uint flags, IntPtr id, string? text);

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
