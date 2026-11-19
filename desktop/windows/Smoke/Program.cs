using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.Linq;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Porta.Desktop;

// Drives the packaged runtime inside a real WSL, the way the window does:
// the same launch plan, relay frames, filter and close protocol, with no window.
// Usage: PortaDesktop.Smoke <runtime folder> <project folder>

var token = LaunchPlan.NewToken();
var runtime = Wsl("wslpath", "-a", "-u", args[0]);
var project = Wsl("wslpath", "-a", "-u", args[1]);
var architecture = LaunchPlan.Architecture(Wsl("uname", "-m")) ?? throw new InvalidOperationException("no PHP for this distribution");
Console.WriteLine($"smoke: runtime {runtime}, project {project}");

var start = new ProcessStartInfo(LaunchPlan.Executable, LaunchPlan.CommandLine(LaunchPlan.Arguments(runtime, architecture, project, 120, 36)))
{
    UseShellExecute = false,
    CreateNoWindow = true,
    RedirectStandardInput = true,
    RedirectStandardOutput = true,
};
foreach (var variable in LaunchPlan.Environment(token))
{
    start.EnvironmentVariables[variable.Key] = variable.Value;
}

using var process = Process.Start(start)!;
var messages = new BlockingCollection<(MessageKind Kind, string Root)>();
var screen = new StringBuilder();
var filter = new OscFilter();
var reader = Task.Run(async () =>
{
    var buffer = new byte[65536];
    int count;
    while ((count = await process.StandardOutput.BaseStream.ReadAsync(buffer)) > 0)
    {
        var found = new List<(int Code, string Payload)>();
        var output = filter.Consume(buffer, count, found);
        lock (screen)
        {
            screen.Append(Encoding.UTF8.GetString(output));
        }

        foreach (var (code, payload) in found.Where(entry => entry.Code == OscFilter.Private))
        {
            messages.Add(DesktopMessage.Parse(payload, token));
        }
    }
});

void Send(byte[] frame)
{
    process.StandardInput.BaseStream.Write(frame);
    process.StandardInput.BaseStream.Flush();
}

void Type(string text) => Send(RelayFrame.Input(Encoding.UTF8.GetBytes(text)));

string Screen()
{
    lock (screen)
    {
        return screen.ToString();
    }
}

void Fail(string why)
{
    Console.Error.WriteLine("smoke: " + why);
    // The text Porta drew, without its escape sequences: what a reader can make sense of.
    var text = System.Text.RegularExpressions.Regex.Replace(Screen(), "\u001b(\\[[0-?]*[ -/]*[@-~]|\\][^\u0007\u001b]*(\u0007|\u001b\\\\)|[@-Z\\\\-_])", " ");
    text = System.Text.RegularExpressions.Regex.Replace(text, " {2,}", " ");
    Console.Error.WriteLine("smoke: the screen ended with: " + new string(text.TakeLast(3000).ToArray()));
    process.Kill();
    Environment.Exit(1);
}

(MessageKind Kind, string Root) Await(MessageKind expected, TimeSpan patience)
{
    var deadline = DateTime.UtcNow + patience;
    while (DateTime.UtcNow < deadline)
    {
        if (messages.TryTake(out var message, 250) && message.Kind == expected)
        {
            return message;
        }

        if (process.HasExited)
        {
            Fail($"Porta exited with {process.ExitCode} while waiting for {expected}");
        }
    }

    Fail($"no {expected} within {patience.TotalSeconds}s");
    return default;
}

void AwaitScreen(int from, string text, string what)
{
    var deadline = DateTime.UtcNow.AddSeconds(30);
    while (DateTime.UtcNow < deadline)
    {
        var shown = Screen();
        if (shown.IndexOf(text, Math.Min(from, shown.Length), StringComparison.Ordinal) >= 0)
        {
            return;
        }

        Thread.Sleep(250);
    }

    Fail("no " + what + " on screen");
}

void AwaitState(bool unsaved)
{
    var deadline = DateTime.UtcNow.AddSeconds(30);
    while (DateTime.UtcNow < deadline)
    {
        var state = Await(MessageKind.State, deadline - DateTime.UtcNow);
        if ((state.Root != "0") == unsaved)
        {
            return;
        }
    }

    Fail(unsaved ? "no unsaved file was told" : "the save was not told");
}

var trustDeadline = DateTime.UtcNow.AddSeconds(120);
while (!Screen().Contains("Trust this project") && DateTime.UtcNow < trustDeadline && !process.HasExited)
{
    Thread.Sleep(250);
}

// Enter answers no: the project opens restricted.
Type("\r");
var ready = Await(MessageKind.Ready, TimeSpan.FromSeconds(120));
if (ready.Root != project)
{
    Fail($"Porta opened {ready.Root}");
}

Console.WriteLine("smoke: the IDE is up inside WSL");

Send(RelayFrame.Resize(100, 30));
Thread.Sleep(1000);

// The first run opens on the welcome card.
System.IO.File.WriteAllText(System.IO.Path.Combine(args[1], "a.php"), "<?php\n");
AwaitScreen(0, "Welcome", "the welcome card");

// The Escape key on the welcome card closes it, and the keys after it still arrive.
Thread.Sleep(1000);
var beforeEscape = Screen().Length;
Type("\u001b");
Thread.Sleep(3000);
Console.WriteLine($"smoke: Escape on the welcome card redrew {Screen().Length - beforeEscape} bytes");
var beforeOpen = Screen().Length;

// A file of the project, as the window hands it over, opens here; an edit is told, and so is its save.
Type(DesktopMessage.OpenRequest(token, project + "/a.php"));
AwaitScreen(beforeOpen, "a.php", "the opened file's tab");
Thread.Sleep(500);
Type("x");
AwaitState(unsaved: true);
Console.WriteLine("smoke: the file opened, and its edit was told");
Type("\u0013");
AwaitState(unsaved: false);
Console.WriteLine("smoke: its save was told");

// The Escape key reaches Porta as a lone ESC byte; the keys after it must still arrive.
Type("\u001b");
Thread.Sleep(1000);
Type("y");
AwaitState(unsaved: true);
Type("\u0013");
AwaitState(unsaved: false);
Console.WriteLine("smoke: the keys after a lone Escape still arrive");

Type(DesktopMessage.CloseRequest(new string('f', 32)));
if (messages.TryTake(out var answer, 3000) && answer.Kind == MessageKind.Closing)
{
    Fail("a forged token closed the session");
}

Console.WriteLine("smoke: a forged close was ignored");

var flow = new CloseFlow();
flow.Received(MessageKind.Ready);
Type(DesktopMessage.CloseRequest(token));
flow.Received(Await(MessageKind.Closing, TimeSpan.FromSeconds(30)).Kind);
if (!process.WaitForExit(30000))
{
    Fail("Porta did not exit after saying it was closing");
}

await reader;
if (!flow.ClosesWhenPortaExits(process.ExitCode))
{
    Fail($"the window would have stayed open on exit status {process.ExitCode}");
}

Console.WriteLine($"smoke: Porta closed cleanly with status {process.ExitCode}");
return 0;

static string Wsl(params string[] command)
{
    var start = new ProcessStartInfo(LaunchPlan.Executable, LaunchPlan.CommandLine(new[] { "--exec" }.Concat(command)))
    {
        UseShellExecute = false,
        RedirectStandardOutput = true,
        StandardOutputEncoding = Encoding.UTF8,
    };
    using var process = Process.Start(start)!;
    var answer = process.StandardOutput.ReadToEnd().Trim();
    process.WaitForExit();
    return answer;
}
