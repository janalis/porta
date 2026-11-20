using System;
using System.Diagnostics;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;

namespace Porta.Desktop.App
{
    /// <summary>
    /// What Porta needs from Windows before it can run, with the one step that helps: the Windows
    /// Subsystem for Linux, installed with administrator rights; a Linux distribution, when a first
    /// install left none; or WSL's own words, when the distribution does not answer. The macOS
    /// application shows the same sheet for its own permissions.
    /// </summary>
    public sealed class PermissionsWindow : Window
    {
        private readonly WslNeed need;
        private readonly ContentControl accessory = new ContentControl { VerticalAlignment = VerticalAlignment.Center };
        private readonly TextBlock title = new TextBlock();
        private readonly TextBlock subtitle = new TextBlock();
        private readonly TextBox detail = new TextBox();
        private DispatcherTimer? watch;

        /// <summary>Porta can run now: the registry opens the project again, then closes this window.</summary>
        public event Action? Retry;

        /// <param name="need">what is missing</param>
        /// <param name="reason">WSL's own words, for <see cref="WslNeed.Start"/></param>
        public PermissionsWindow(WslNeed need, string reason)
        {
            this.need = need;
            // The titles the smoke tests and Windows' own task switcher go by.
            Title = need switch
            {
                WslNeed.Wsl => "Porta needs WSL",
                WslNeed.Distribution => "Porta needs a Linux distribution",
                _ => "Porta could not start Linux",
            };
            Width = 520;
            SizeToContent = SizeToContent.Height;
            ResizeMode = ResizeMode.NoResize;
            WindowStartupLocation = WindowStartupLocation.CenterScreen;
            Background = SystemColors.WindowBrush;
            Content = Layout(reason);
            ShowAction();
            Closed += (_, _) => watch?.Stop();
        }

        /// <summary>The window's words for what is missing: heading, explanation, and the row's name and detail.</summary>
        private static (string Heading, string Explanation, string Name, string About) Texts(WslNeed need, string reason) =>
            need switch
            {
                WslNeed.Wsl => (
                    "Porta needs the Windows Subsystem for Linux",
                    "Porta runs its terminals, your tools and PHP inside Linux. Windows installs it once, with administrator rights.",
                    "Windows Subsystem for Linux",
                    "Where Porta runs, with the project's files"),
                WslNeed.Distribution => (
                    "Porta needs a Linux distribution",
                    "WSL is installed, with no Linux in it yet. Porta runs inside a distribution: Ubuntu, unless you add another.",
                    "Ubuntu",
                    "Asks you to choose a Linux user name and password"),
                _ => (
                    "Porta could not start Linux",
                    "Porta runs inside WSL, which answered:",
                    "Windows Subsystem for Linux",
                    reason),
            };

        private UIElement Layout(string reason)
        {
            var (heading, explanation, name, about) = Texts(need, reason);
            var icon = new Image { Width = 64, Height = 64, Margin = new Thickness(0, 8, 0, 12), Source = AppIcon() };
            title.Text = heading;
            title.FontSize = 20;
            title.FontWeight = FontWeights.SemiBold;
            title.TextAlignment = TextAlignment.Center;
            title.TextWrapping = TextWrapping.Wrap;
            subtitle.Text = explanation;
            subtitle.Foreground = SystemColors.GrayTextBrush;
            subtitle.TextAlignment = TextAlignment.Center;
            subtitle.TextWrapping = TextWrapping.Wrap;
            subtitle.Margin = new Thickness(0, 8, 0, 20);

            var badge = new Border
            {
                Width = 32,
                Height = 32,
                CornerRadius = new CornerRadius(7),
                Background = new SolidColorBrush(Color.FromRgb(0x00, 0x78, 0xD4)),
                VerticalAlignment = VerticalAlignment.Top,
                Child = new TextBlock
                {
                    // CommandPrompt, in the system's icon font: escaped, as a private-use letter is lost in an edit.
                    Text = "\uE756",
                    FontFamily = new FontFamily("Segoe Fluent Icons, Segoe MDL2 Assets"),
                    FontSize = 16,
                    Foreground = Brushes.White,
                    HorizontalAlignment = HorizontalAlignment.Center,
                    VerticalAlignment = VerticalAlignment.Center,
                },
            };
            var words = new StackPanel { Margin = new Thickness(12, 0, 12, 0), VerticalAlignment = VerticalAlignment.Center };
            words.Children.Add(new TextBlock { Text = name, FontWeight = FontWeights.SemiBold });
            // WSL's answer can be several lines: selectable, so it can be searched for.
            detail.Text = about;
            detail.FontSize = 11;
            detail.Foreground = SystemColors.GrayTextBrush;
            detail.TextWrapping = TextWrapping.Wrap;
            detail.IsReadOnly = true;
            detail.BorderThickness = new Thickness(0);
            detail.Background = Brushes.Transparent;
            detail.Padding = new Thickness(0);
            words.Children.Add(detail);

            var line = new DockPanel { Margin = new Thickness(14, 12, 14, 12) };
            DockPanel.SetDock(badge, Dock.Left);
            DockPanel.SetDock(accessory, Dock.Right);
            line.Children.Add(badge);
            line.Children.Add(accessory);
            line.Children.Add(words);
            var row = new Border
            {
                CornerRadius = new CornerRadius(10),
                BorderThickness = new Thickness(1),
                BorderBrush = SystemColors.ActiveBorderBrush,
                Background = SystemColors.ControlLightLightBrush,
                Child = line,
            };

            var close = new Button { Content = "Close", MinWidth = 88, Padding = new Thickness(12, 4, 12, 4), IsCancel = true, IsDefault = true };
            close.Click += (_, _) => Close();
            var buttons = new StackPanel { Orientation = Orientation.Horizontal, HorizontalAlignment = HorizontalAlignment.Right, Margin = new Thickness(0, 20, 0, 0) };
            buttons.Children.Add(close);

            var stack = new StackPanel { Margin = new Thickness(32, 24, 32, 20) };
            stack.Children.Add(icon);
            stack.Children.Add(title);
            stack.Children.Add(subtitle);
            stack.Children.Add(row);
            stack.Children.Add(buttons);
            return stack;
        }

        private void ShowAction()
        {
            var label = need switch
            {
                WslNeed.Wsl => "Install WSL",
                WslNeed.Distribution => "Install Ubuntu",
                _ => "Try Again",
            };
            var action = new Button { Content = label, Padding = new Thickness(12, 4, 12, 4) };
            action.Click += (_, _) =>
            {
                switch (need)
                {
                    case WslNeed.Wsl:
                        Install();
                        break;
                    case WslNeed.Distribution:
                        InstallDistribution();
                        break;
                    default:
                        // Once: the check takes as long as WSL takes to answer, or not.
                        Say("Checking…");
                        Retry?.Invoke();
                        break;
                }
            };
            accessory.Content = action;
        }

        /// <summary><c>wsl --install</c>, elevated: Windows asks for administrator rights itself.</summary>
        private void Install()
        {
            try
            {
                Process.Start(new ProcessStartInfo(LaunchPlan.Executable, "--install") { UseShellExecute = true, Verb = "runas" })?.Dispose();
            }
            catch (System.ComponentModel.Win32Exception)
            {
                // Administrator rights refused: nothing was started, and the button stays.
                return;
            }

            // A first install may leave WSL without a distribution: the next launch offers one.
            Say("Installing: restart Windows when it is done, then open Porta");
        }

        /// <summary>
        /// Ubuntu, in two steps: installed out of sight, so that a failure is told here rather than in a
        /// console that closes on it; then started in a console of its own, where it asks for a user.
        /// </summary>
        private async void InstallDistribution()
        {
            // A try after a failed one: the window says what it does now.
            var (heading, explanation, _, about) = Texts(WslNeed.Distribution, "");
            title.Text = heading;
            subtitle.Text = explanation;
            detail.Text = about;
            Say("Installing Ubuntu: it is downloaded, which can take a few minutes");
            var said = await Task.Run(() => WslShell.Run(new[] { "--install", "--no-launch" }, TimeSpan.FromMinutes(30)));
            if (WslShell.Distributions() == 0)
            {
                // Without virtualization, for one: WSL's words say what to change.
                title.Text = "Ubuntu could not be installed";
                subtitle.Text = "Porta runs inside WSL, which answered:";
                detail.Text = WslCheck.Reason(said.All);
                ShowAction();
                return;
            }

            OpenUbuntu();
        }

        /// <summary>
        /// Ubuntu's own console, where its first start asks for a user. Linux is left alone while it is
        /// open (a check from here could take that first start for itself); once it is closed, Porta
        /// opens as that user, never as root.
        /// </summary>
        private void OpenUbuntu()
        {
            Process? console;
            try
            {
                console = Process.Start(new ProcessStartInfo(LaunchPlan.Executable) { UseShellExecute = true });
            }
            catch (System.ComponentModel.Win32Exception exception)
            {
                // No console, no first start to wait for: say so, rather than ask Linux and start it here.
                detail.Text = "Ubuntu's own window could not be opened: " + exception.Message;
                AskToOpenUbuntu();
                return;
            }

            Say("Choose a Linux user in the window that opened, then close it: Porta opens");
            var timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(1) };
            watch = timer;
            timer.Tick += async (_, _) =>
            {
                if (console?.HasExited == false)
                {
                    return;
                }

                timer.Stop();
                console?.Dispose();
                var user = await Task.Run(() => WslShell.Exec("id", "-u"));
                if (user == "0")
                {
                    // Closed before Ubuntu had its user: a project opened as root would leave root's
                    // files in it. Ubuntu asks for a user at its first start only, so opening it again
                    // gives a root shell: the steps that make one are what helps.
                    detail.Text = "Ubuntu has no user of its own yet, and Porta does not run your projects as root. "
                        + "In Ubuntu's window: adduser <name>; put \"[user]\" and \"default=<name>\" in /etc/wsl.conf; "
                        + "then wsl.exe --shutdown, which restarts it as that user and closes the window, and Porta opens.";
                    AskToOpenUbuntu();
                    return;
                }

                // A user, or no answer at all: the registry checks again, and shows what it finds.
                Retry?.Invoke();
            };
            timer.Start();
        }

        private void AskToOpenUbuntu()
        {
            var again = new Button { Content = "Open Ubuntu", Padding = new Thickness(12, 4, 12, 4) };
            again.Click += (_, _) => OpenUbuntu();
            accessory.Content = again;
        }

        private void Say(string text)
        {
            accessory.Content = new TextBlock
            {
                Text = text,
                FontSize = 11,
                Foreground = SystemColors.GrayTextBrush,
                TextWrapping = TextWrapping.Wrap,
                MaxWidth = 170,
            };
        }

        private static ImageSource? AppIcon()
        {
            using var icon = System.Drawing.Icon.ExtractAssociatedIcon(Process.GetCurrentProcess().MainModule!.FileName!);
            return icon == null ? null : Imaging.CreateBitmapSourceFromHIcon(icon.Handle, Int32Rect.Empty, BitmapSizeOptions.FromEmptyOptions());
        }
    }
}
