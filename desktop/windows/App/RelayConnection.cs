using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Text;
using System.Threading.Tasks;
using Microsoft.Terminal.Wpf;

namespace Porta.Desktop.App
{
    /// <summary>The terminal control's connection: Porta's bytes, through wsl.exe's plain pipes.</summary>
    public sealed class RelayConnection : ITerminalConnection
    {
        private const int ReadSize = 65536;

        private readonly string runtime;
        private readonly string architecture;
        private readonly string? project;
        private readonly string token;
        private readonly OscFilter filter = new OscFilter();
        private readonly Decoder decoder = Encoding.UTF8.GetDecoder();
        private readonly object writing = new object();
        private Process? process;
        private int columns = 120;
        private int rows = 36;

        public RelayConnection(string runtime, string architecture, string? project, string token, bool dark)
        {
            this.runtime = runtime;
            this.architecture = architecture;
            this.project = project;
            this.token = token;
            this.dark = dark;
        }

        private readonly bool dark;

        public event EventHandler<TerminalOutputEventArgs>? TerminalOutput;

        public event Action<int, string>? PrivateSequence;

        public event Action<int>? Exited;

        public void Start()
        {
            var start = new ProcessStartInfo(LaunchPlan.Executable, LaunchPlan.CommandLine(LaunchPlan.Arguments(runtime, architecture, project, columns, rows)))
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardInput = true,
                RedirectStandardOutput = true,
            };
            foreach (var variable in LaunchPlan.Environment(token, dark))
            {
                start.EnvironmentVariables[variable.Key] = variable.Value;
            }

            process = Process.Start(start)!;
            Task.Run(ReadLoop);
        }

        public void WriteInput(string data) => Send(RelayFrame.Input(Encoding.UTF8.GetBytes(data)));

        public void Resize(uint newRows, uint newColumns)
        {
            rows = (int)newRows;
            columns = (int)newColumns;
            if (process != null)
            {
                Send(RelayFrame.Resize(columns, rows));
            }
        }

        public void Close()
        {
            try
            {
                process?.StandardInput.Close();
            }
            catch (InvalidOperationException)
            {
                // Already gone.
            }
        }

        private void Send(byte[] frame)
        {
            lock (writing)
            {
                try
                {
                    process?.StandardInput.BaseStream.Write(frame, 0, frame.Length);
                    process?.StandardInput.BaseStream.Flush();
                }
                catch (System.IO.IOException)
                {
                    // The relay has gone; its exit is reported by the read loop.
                }
            }
        }

        private async Task ReadLoop()
        {
            var buffer = new byte[ReadSize];
            var stream = process!.StandardOutput.BaseStream;
            int count;
            while ((count = await stream.ReadAsync(buffer, 0, buffer.Length)) > 0)
            {
                var found = new List<(int Code, string Payload)>();
                var output = filter.Consume(buffer, count, found);
                var characters = new char[decoder.GetCharCount(output, 0, output.Length)];
                var length = decoder.GetChars(output, 0, output.Length, characters, 0);
                TerminalOutput?.Invoke(this, new TerminalOutputEventArgs(new string(characters, 0, length)));
                foreach (var (code, payload) in found)
                {
                    PrivateSequence?.Invoke(code, payload);
                }
            }

            process.WaitForExit();
            Exited?.Invoke(process.ExitCode);
        }
    }
}
