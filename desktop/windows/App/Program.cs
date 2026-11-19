using System;
using System.IO;
using System.IO.Pipes;
using System.Linq;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;

namespace Porta.Desktop.App
{
    /// <summary>One process per user: a later launch hands its folder to the first and leaves.</summary>
    public static class Program
    {
        private static readonly string User = WindowsIdentity.GetCurrent().User!.Value;
        private static readonly string PipeName = "porta-desktop-" + User;

        [STAThread]
        public static void Main(string[] arguments)
        {
            var project = arguments.FirstOrDefault() ?? "";
            using var instance = new Mutex(true, @"Local\io.github.janalis.porta-" + User, out var first);
            if (!first)
            {
                HandOver(project);
                return;
            }

            var application = new Application { ShutdownMode = ShutdownMode.OnLastWindowClose };
            var windows = new WindowRegistry();
            application.Startup += (_, _) =>
            {
                Task.Run(() => Listen(application, windows));
                windows.Open(project.Length > 0 ? project : null);
            };
            application.Run();
        }

        private static void HandOver(string project)
        {
            using var pipe = new NamedPipeClientStream(".", PipeName, PipeDirection.Out);
            pipe.Connect(3000);
            using var writer = new StreamWriter(pipe);
            writer.WriteLine(project);
        }

        private static void Listen(Application application, WindowRegistry windows)
        {
            var security = new PipeSecurity();
            security.AddAccessRule(new PipeAccessRule(WindowsIdentity.GetCurrent().User!, PipeAccessRights.FullControl, AccessControlType.Allow));
            while (true)
            {
                using var pipe = new NamedPipeServerStream(PipeName, PipeDirection.In, 1, PipeTransmissionMode.Byte, PipeOptions.None, 4096, 4096, security);
                pipe.WaitForConnection();
                using var reader = new StreamReader(pipe);
                var project = reader.ReadLine() ?? "";
                application.Dispatcher.Invoke(() => windows.Open(project.Length > 0 ? project : null));
            }
        }
    }
}
