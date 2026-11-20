using System;
using System.Diagnostics;
using System.IO;
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
        private readonly Func<bool> ready;
        private readonly ContentControl accessory = new ContentControl { VerticalAlignment = VerticalAlignment.Center };
        private DispatcherTimer? watch;

        /// <summary>Porta can run now: the registry opens the project again, then closes this window.</summary>
        public event Action? Retry;

        /// <param name="need">what is missing</param>
        /// <param name="reason">WSL's own words, for <see cref="WslNeed.Start"/></param>
        /// <param name="ready">whether Linux answers now; slow, so called off the interface's thread</param>
        public PermissionsWindow(WslNeed need, string reason, Func<bool> ready)
        {
            this.need = need;
            this.ready = ready;
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

        private UIElement Layout(string reason)
        {
            var (heading, explanation, name, detail) = need switch
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

            var icon = new Image { Width = 64, Height = 64, Margin = new Thickness(0, 8, 0, 12), Source = AppIcon() };
            var title = new TextBlock
            {
                Text = heading,
                FontSize = 20,
                FontWeight = FontWeights.SemiBold,
                TextAlignment = TextAlignment.Center,
                TextWrapping = TextWrapping.Wrap,
            };
            var subtitle = new TextBlock
            {
                Text = explanation,
                Foreground = SystemColors.GrayTextBrush,
                TextAlignment = TextAlignment.Center,
                TextWrapping = TextWrapping.Wrap,
                Margin = new Thickness(0, 8, 0, 20),
            };

            var badge = new Border
            {
                Width = 32,
                Height = 32,
                CornerRadius = new CornerRadius(7),
                Background = new SolidColorBrush(Color.FromRgb(0x00, 0x78, 0xD4)),
                VerticalAlignment = VerticalAlignment.Top,
                Child = new TextBlock
                {
                    Text = "", // CommandPrompt, in the system's icon font
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
            words.Children.Add(new TextBox
            {
                Text = detail,
                FontSize = 11,
                Foreground = SystemColors.GrayTextBrush,
                TextWrapping = TextWrapping.Wrap,
                IsReadOnly = true,
                BorderThickness = new Thickness(0),
                Background = Brushes.Transparent,
                Padding = new Thickness(0),
            });

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
            if (!Start(administrator: true))
            {
                return;
            }

            // A first install may leave WSL without a distribution: the next launch offers one.
            Say("Installing: restart Windows when it is done, then open Porta");
        }

        /// <summary>
        /// <c>wsl --install</c> again, as the user: with WSL there, it adds Ubuntu, in a console of its
        /// own where Ubuntu asks for a user. Porta opens by itself once Linux answers.
        /// </summary>
        private void InstallDistribution()
        {
            if (!Start(administrator: false))
            {
                return;
            }

            Say("Installing: finish in the window that opened, and Porta opens by itself");
            var busy = false;
            watch = new DispatcherTimer { Interval = TimeSpan.FromSeconds(3) };
            watch.Tick += async (_, _) =>
            {
                if (busy)
                {
                    return;
                }

                busy = true;
                var answers = await Task.Run(ready);
                busy = false;
                if (answers && watch.IsEnabled)
                {
                    watch.Stop();
                    Retry?.Invoke();
                }
            };
            watch.Start();
        }

        private static bool Start(bool administrator)
        {
            try
            {
                Process.Start(new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "wsl.exe"), "--install")
                {
                    UseShellExecute = true,
                    Verb = administrator ? "runas" : "",
                })?.Dispose();
                return true;
            }
            catch (System.ComponentModel.Win32Exception)
            {
                // Administrator rights refused: nothing was started, and the button stays.
                return false;
            }
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
