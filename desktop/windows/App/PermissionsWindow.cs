using System;
using System.Diagnostics;
using System.IO;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Media.Imaging;

namespace Porta.Desktop.App
{
    /// <summary>
    /// What Porta needs from Windows before it can run, with the way to get it: the Windows
    /// Subsystem for Linux, installed from here with administrator rights. The macOS application
    /// shows the same sheet for its own permissions.
    /// </summary>
    public sealed class PermissionsWindow : Window
    {
        private readonly ContentControl accessory = new ContentControl { VerticalAlignment = VerticalAlignment.Center };

        public PermissionsWindow()
        {
            // The title the smoke tests and Windows' own task switcher go by.
            Title = "Porta needs WSL";
            Width = 520;
            SizeToContent = SizeToContent.Height;
            ResizeMode = ResizeMode.NoResize;
            WindowStartupLocation = WindowStartupLocation.CenterScreen;
            Background = SystemColors.WindowBrush;
            Content = Layout();
            ShowInstall();
        }

        private UIElement Layout()
        {
            var icon = new Image { Width = 64, Height = 64, Margin = new Thickness(0, 8, 0, 12), Source = AppIcon() };
            var title = new TextBlock
            {
                Text = "Porta needs the Windows Subsystem for Linux",
                FontSize = 20,
                FontWeight = FontWeights.SemiBold,
                TextAlignment = TextAlignment.Center,
                TextWrapping = TextWrapping.Wrap,
            };
            var subtitle = new TextBlock
            {
                Text = "Porta runs its terminals, your tools and PHP inside Linux. Windows installs it once, with administrator rights.",
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
                Child = new TextBlock
                {
                    Text = "", // CommandPrompt, in the system's icon font
                    FontFamily = new FontFamily("Segoe Fluent Icons, Segoe MDL2 Assets"),
                    FontSize = 16,
                    Foreground = Brushes.White,
                    HorizontalAlignment = HorizontalAlignment.Center,
                    VerticalAlignment = VerticalAlignment.Center,
                },
            };
            var words = new StackPanel { Margin = new Thickness(12, 0, 12, 0), VerticalAlignment = VerticalAlignment.Center };
            words.Children.Add(new TextBlock { Text = "Windows Subsystem for Linux", FontWeight = FontWeights.SemiBold });
            words.Children.Add(new TextBlock { Text = "Where Porta runs, with the project's files", FontSize = 11, Foreground = SystemColors.GrayTextBrush, TextWrapping = TextWrapping.Wrap });

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

        private void ShowInstall()
        {
            var install = new Button { Content = "Install WSL", Padding = new Thickness(12, 4, 12, 4) };
            install.Click += (_, _) => Install();
            accessory.Content = install;
        }

        /// <summary><c>wsl --install</c>, elevated: Windows asks for administrator rights itself.</summary>
        private void Install()
        {
            try
            {
                Process.Start(new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "wsl.exe"), "--install")
                {
                    UseShellExecute = true,
                    Verb = "runas",
                });
            }
            catch (System.ComponentModel.Win32Exception)
            {
                // Administrator rights refused: nothing was started, and the button stays.
                return;
            }

            accessory.Content = new TextBlock
            {
                Text = "Installing: restart Windows when it is done, then open Porta",
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
