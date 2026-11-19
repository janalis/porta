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
        private readonly CloseFlow flow = new CloseFlow();
        private readonly RelayConnection connection;
        private readonly DispatcherTimer answer = new DispatcherTimer { Interval = AnswerTime };
        private bool exited;

        public ProjectWindow(string runtime, string? project)
        {
            Root = project;
            Title = "Porta";
            Background = Brushes.Black;
            RestorePlacement();

            connection = new RelayConnection(runtime, project, token);
            connection.PrivateSequence += (code, payload) => Dispatcher.Invoke(() => Received(code, payload));
            connection.Exited += status => Dispatcher.Invoke(() => PortaExited(status));
            answer.Tick += (_, _) => AnswerTimedOut();

            var terminal = new TerminalControl();
            Content = terminal;
            Loaded += (_, _) =>
            {
                terminal.SetTheme(Theme.Dark, "Cascadia Mono", 11);
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
                Title = root.Substring(root.LastIndexOf('/') + 1) + " — Porta";
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

        private void PortaExited(int status)
        {
            exited = true;
            answer.Stop();
            if (flow.ClosesWhenPortaExits(status))
            {
                Close();
                return;
            }

            Title = "Porta — stopped";
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
