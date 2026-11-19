using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Runtime.CompilerServices;
using System.Text;
using System.Text.Json;
using Porta.Desktop;
using Xunit;

public class CoreTests
{
    private static JsonElement Examples([CallerFilePath] string here = "")
    {
        var file = Path.Combine(Path.GetDirectoryName(here)!, "..", "..", "contract", "protocol.json");
        return JsonDocument.Parse(File.ReadAllText(file)).RootElement.GetProperty("examples");
    }

    private static string Example(string name) => Examples().GetProperty(name).GetString()!;

    private static string Payload(string sequence) => sequence.Substring(7, sequence.Length - 9);

    [Fact]
    public void MessagesReadAsTheContractWritesThem()
    {
        var token = Example("token");

        Assert.Equal((MessageKind.Ready, Example("root")), DesktopMessage.Parse(Payload(Example("ready")), token));
        Assert.Equal(MessageKind.Accepted, DesktopMessage.Parse(Payload(Example("accepted")), token).Kind);
        Assert.Equal(MessageKind.Closing, DesktopMessage.Parse(Payload(Example("closing")), token).Kind);
        Assert.Equal(Example("close"), DesktopMessage.CloseRequest(token));
    }

    [Fact]
    public void AnotherSessionsTokenIsNotListenedTo()
    {
        Assert.Equal(MessageKind.None, DesktopMessage.Parse(Payload(Example("closing")), new string('f', 32)).Kind);
    }

    [Fact]
    public void ASplitAtEveryByteChangesNothing()
    {
        var whole = Encoding.ASCII.GetBytes("a\u001b]7777;porta-desktop;t;closing\u001b\\b");
        for (var cut = 0; cut <= whole.Length; cut++)
        {
            var filter = new OscFilter();
            var found = new List<(int Code, string Payload)>();
            var output = filter.Consume(whole.Take(cut).ToArray(), cut, found)
                .Concat(filter.Consume(whole.Skip(cut).ToArray(), whole.Length - cut, found)).ToArray();

            Assert.Equal("ab", Encoding.ASCII.GetString(output));
            Assert.Equal((OscFilter.Private, "porta-desktop;t;closing"), Assert.Single(found));
        }
    }

    [Fact]
    public void TheClipboardIsCaughtAndOtherEscapesPass()
    {
        const string others = "\u001b[31mred\u001b]0;title\u0007\u001b]777;x\u0007";
        var bytes = Encoding.ASCII.GetBytes(others + "\u001b]52;c;aGk=\u0007");
        var found = new List<(int Code, string Payload)>();

        var output = new OscFilter().Consume(bytes, bytes.Length, found);

        Assert.Equal(others, Encoding.ASCII.GetString(output));
        Assert.Equal((OscFilter.Clipboard, "c;aGk="), Assert.Single(found));
    }

    [Fact]
    public void TheCloseFlowProtectsUnsavedWork()
    {
        var flow = new CloseFlow();
        Assert.Equal(CloseStep.CloseNow, flow.CloseRequested());
        Assert.False(flow.ClosesWhenPortaExits(129));

        flow.Received(MessageKind.Ready);
        Assert.Equal(CloseStep.AskPorta, flow.CloseRequested());
        Assert.Equal(CloseStep.Wait, flow.CloseRequested());
        Assert.Equal(CloseStep.StopWaiting, flow.Received(MessageKind.Accepted));
        Assert.Equal(CloseStep.AskPorta, flow.CloseRequested());
        Assert.Equal(CloseStep.OfferForceQuit, flow.AnswerTimedOut());
        Assert.Equal(CloseStep.Wait, flow.AnswerTimedOut());
        Assert.True(flow.ClosesWhenPortaExits(0));
        Assert.True(flow.ClosesWhenPortaExits(129));
        Assert.False(flow.ClosesWhenPortaExits(1));
    }

    [Fact]
    public void PortaRunsThroughTheRelayOnTheBundledPhp()
    {
        var arguments = LaunchPlan.Arguments("/mnt/c/Porta/runtime", "/home/me/shop", 120, 36);

        Assert.Equal(new[] { "--cd", "~", "--exec", "/mnt/c/Porta/runtime/php", "-n", "/mnt/c/Porta/runtime/wsl-relay.php", "120", "36" }, arguments.Take(8));
        Assert.Equal("/home/me/shop", arguments.Last());
        Assert.Equal("/mnt/c/Porta/runtime/porta.phar", LaunchPlan.Arguments("/mnt/c/Porta/runtime", null, 80, 24).Last());
    }

    [Fact]
    public void TheSessionCrossesIntoWslAndClaimsNoSystemMenu()
    {
        var environment = LaunchPlan.Environment("tok");

        Assert.Contains("PORTA_DESKTOP_TOKEN/u", environment["WSLENV"]);
        Assert.False(environment.ContainsKey("PORTA_DESKTOP_MENU"));
    }

    [Theory]
    [InlineData("plain", "plain")]
    [InlineData("two words", "\"two words\"")]
    [InlineData("say \"hi\"", "\"say \\\"hi\\\"\"")]
    [InlineData("C:\\Program Files\\", "\"C:\\Program Files\\\\\"")]
    [InlineData("", "\"\"")]
    public void ArgumentsAreQuotedTheWayWindowsSplitsThem(string argument, string expected)
    {
        Assert.Equal(expected, LaunchPlan.CommandLine(new[] { argument }));
    }

    [Fact]
    public void FramesMatchTheRelay()
    {
        Assert.Equal(new byte[] { 0, 0, 0, 0, 2, 104, 105 }, RelayFrame.Input(Encoding.ASCII.GetBytes("hi")));
        Assert.Equal(new byte[] { 1, 0, 132, 0, 43 }, RelayFrame.Resize(132, 43));
    }

    [Fact]
    public void TokensAreWhatPortaInsistsOn()
    {
        Assert.Matches("^[0-9a-f]{32}$", LaunchPlan.NewToken());
    }
}
