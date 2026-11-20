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

// The text Porta drew, without its escape sequences, as a reader can make sense of it: its last `length` characters.
string Readable(string drawn, int length)
{
    var text = System.Text.RegularExpressions.Regex.Replace(drawn, @"\x1b(\[[0-?]*[ -/]*[@-~]|\][^\x07\x1b]*(\x07|\x1b\\)|[@-Z\\-_])", " ");
    text = System.Text.RegularExpressions.Regex.Replace(text, " {2,}", " ");
    return new string(text.TakeLast(length).ToArray());
}

void Fail(string why)
{
    Console.Error.WriteLine("smoke: " + why);
    Console.Error.WriteLine("smoke: the screen ended with: " + Readable(Screen(), 3000));
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
var readyAt = Screen().Length;

Send(RelayFrame.Resize(100, 30));
Thread.Sleep(1000);

// The first run opens on the welcome card.
System.IO.File.WriteAllText(System.IO.Path.Combine(args[1], "a.php"), "<?php\n");
AwaitScreen(0, "Welcome", "the welcome card");

// The Escape key on the welcome card, the moment it is drawn: once, a first run sent it then and
// Porta drew nothing more. Reported, not failed, until it is understood; a second Escape follows if so.
var shownBeforeEscape = Screen();
var beforeEscape = shownBeforeEscape.Length;
// At once, as the card is drawn: work done before it delays the key, and a delay hides the race.
Type("\u001b");
// Diagnostic, from the screen as it was: whether the card is drawn since Porta was ready.
var welcomeAt = shownBeforeEscape.LastIndexOf("Welcome", StringComparison.Ordinal);
Console.WriteLine($"smoke: diagnostic: 'Welcome' last drawn at {welcomeAt - readyAt} bytes after ready, of {beforeEscape - readyAt}; what was drawn after it: {Readable(shownBeforeEscape.Substring(Math.Max(0, welcomeAt)), 2500)}");
Thread.Sleep(3000);
var redrawn = Screen().Length - beforeEscape;
Console.WriteLine($"smoke: Escape at the welcome card's first paint redrew {redrawn} bytes");
if (redrawn == 0)
{
    var beforeSecond = Screen().Length;
    Type("\u001b");
    Thread.Sleep(3000);
    Console.WriteLine($"smoke: diagnostic: a second Escape redrew {Screen().Length - beforeSecond} bytes");
}

var beforeOpen = Screen().Length;

// A file of the project, as the window hands it over, opens here; an edit is told, and so is its save.
Type(DesktopMessage.OpenRequest(token, project + "/a.php"));
AwaitScreen(beforeOpen, "a.php", "the opened file's tab");
// Diagnostic: a held Escape would act now, with the next bytes: does the card close here?
Thread.Sleep(1000);
var afterOpen = Screen().Substring(beforeOpen);
Console.WriteLine($"smoke: diagnostic: opening a.php drew {afterOpen.Length} bytes; 'Welcome' among them: {afterOpen.Contains("Welcome")}; they begin: {Readable(afterOpen.Substring(0, Math.Min(afterOpen.Length, 1500)), 300)}");
Thread.Sleep(500);
Type("x");
AwaitState(unsaved: true);
Console.WriteLine("smoke: the file opened, and its edit was told");
Type("\u0013");
AwaitState(unsaved: false);
Console.WriteLine("smoke: its save was told");

// Escape closes what it opens: Go to File (Ctrl+P, one byte in any terminal), then a lone ESC.
// Reported, not failed, until the welcome card's Escape is understood.
var beforeGoTo = Screen().Length;
Type("\u0010");
Thread.Sleep(3000);
var goTo = Screen().Length - beforeGoTo;
Thread.Sleep(1000);
var beforeClose = Screen().Length;
Type("\u001b");
Thread.Sleep(3000);
Console.WriteLine($"smoke: diagnostic: Go to File drew {goTo} bytes; a lone Escape then redrew {Screen().Length - beforeClose} bytes");

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
