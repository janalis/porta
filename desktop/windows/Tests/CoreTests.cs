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
    public void TheNewerMessagesAreTheContracts()
    {
        var token = Example("token");

        Assert.Equal(Example("open"), DesktopMessage.OpenRequest(token, Example("openPath")));
        Assert.Equal(Example("appearance"), DesktopMessage.Appearance(token, dark: true));
        Assert.Equal((MessageKind.State, "2"), DesktopMessage.Parse(Payload(Example("state")), token));
        Assert.Equal(MessageKind.None, DesktopMessage.Parse($"porta-desktop;{token};state;-1", token).Kind);
        Assert.Equal((MessageKind.Notify, Example("notifyText")), DesktopMessage.Parse(Payload(Example("notify")), token));
        Assert.Equal(MessageKind.None, DesktopMessage.Parse($"porta-desktop;{token};notify;not base64!", token).Kind);
        Assert.Equal(MessageKind.None, DesktopMessage.Parse($"porta-desktop;{token};ready;not base64!", token).Kind);
    }

    [Fact]
    public void ThePickersMessagesAreTheContracts()
    {
        var token = Example("token");

        Assert.Equal((MessageKind.Picking, ""), DesktopMessage.Parse(Payload(Example("picking")), token));
        Assert.Equal((MessageKind.ChooseFolder, ""), DesktopMessage.Parse(Payload(Example("chooseFolder")), token));
        Assert.Equal((MessageKind.Picked, ""), DesktopMessage.Parse(Payload(Example("picked")), token));
        Assert.Equal(MessageKind.None, DesktopMessage.Parse($"porta-desktop;{token};picking;extra", token).Kind);
    }

    [Fact]
    public void TheLookAtLaunchCrossesIntoWslWhenKnown()
    {
        var light = LaunchPlan.Environment("tok", dark: false);

        Assert.Equal("light", light["PORTA_DESKTOP_APPEARANCE"]);
        Assert.EndsWith(":PORTA_DESKTOP_APPEARANCE/u", light["WSLENV"]);
        Assert.Equal("dark", LaunchPlan.Environment("tok", dark: true)["PORTA_DESKTOP_APPEARANCE"]);
        Assert.False(LaunchPlan.Environment("tok").ContainsKey("PORTA_DESKTOP_APPEARANCE"));
        Assert.DoesNotContain("APPEARANCE", LaunchPlan.Environment("tok")["WSLENV"]);
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
    public void ASequenceWrittenIntoThePayloadGoesToTheTerminal()
    {
        var filter = new OscFilter();
        var found = new List<(int Code, string Payload)>();
        var first = Encoding.ASCII.GetBytes("x\u001b]7777;abc\u001b[>7");
        var second = Encoding.ASCII.GetBytes("ude\u001bMf\u001b\\y");

        var output = filter.Consume(first, first.Length, found).Concat(filter.Consume(second, second.Length, found)).ToArray();

        Assert.Equal((OscFilter.Private, "abcdef"), Assert.Single(found));
        Assert.Equal("x\u001b[>7u\u001bMy", Encoding.ASCII.GetString(output));
    }

    [Fact]
    public void AStringSequenceWrittenIntoThePayloadGoesWholeToTheTerminal()
    {
        const string image = "\u001b_Ga=T;AAAA\u001b\\";
        const string title = "\u001b]0;title\u0007";
        var bytes = Encoding.ASCII.GetBytes("\u001b]7777;ab" + image + "cd" + title + "ef\u001b\\");
        var found = new List<(int Code, string Payload)>();

        var output = new OscFilter().Consume(bytes, bytes.Length, found);

        Assert.Equal((OscFilter.Private, "abcdef"), Assert.Single(found));
        Assert.Equal(image + title, Encoding.ASCII.GetString(output));
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
        Assert.Equal(CloseStep.AskPorta, flow.CloseRequested());
        Assert.Equal(CloseStep.WaitForExit, flow.Received(MessageKind.Closing));
        Assert.True(flow.IsClosing);
        Assert.Equal(CloseStep.OfferForceQuit, flow.AnswerTimedOut());
        Assert.True(flow.ClosesWhenPortaExits(0));
        Assert.True(flow.ClosesWhenPortaExits(129));
        Assert.False(flow.ClosesWhenPortaExits(1));
    }

    [Fact]
    public void PortaRunsThroughTheRelayOnTheBundledPhp()
    {
        var arguments = LaunchPlan.Arguments("/mnt/c/Porta/runtime", "aarch64", "/home/me/shop", 120, 36);

        Assert.Equal(new[] { "--cd", "~", "--exec", "/mnt/c/Porta/runtime/aarch64/php", "-n", "/mnt/c/Porta/runtime/wsl-relay.php", "120", "36" }, arguments.Take(8));
        Assert.Equal("/home/me/shop", arguments.Last());
        Assert.Equal("/mnt/c/Porta/runtime/porta.phar", LaunchPlan.Arguments("/mnt/c/Porta/runtime", "x86_64", null, 80, 24).Last());
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

    [Theory]
    [InlineData("x86_64\n", "x86_64")]
    [InlineData("aarch64", "aarch64")]
    [InlineData("wsl: Failed to translate 'C:\\tools'\r\naarch64\r\n", "aarch64")]
    [InlineData("riscv64", null)]
    [InlineData("", null)]
    public void OnlyShippedArchitecturesAreRun(string uname, string? expected)
    {
        Assert.Equal(expected, LaunchPlan.Architecture(uname));
    }

    [Theory]
    [InlineData("{\"fontFamily\":\"JetBrains Mono\",\"fontSize\":14}", "JetBrains Mono", 14.0)]
    [InlineData("{\"fontFamily\":\"\",\"fontSize\":500}", null, null)]
    [InlineData("{\"fontSize\":13.5}", null, 13.5)]
    [InlineData("{\"fontFamily\":3,\"fontSize\":\"14\"}", null, null)]
    [InlineData("not json", null, null)]
    [InlineData(null, null, null)]
    public void SettingsReadOrReadAsNotSet(string? json, string? family, double? size)
    {
        var settings = DesktopSettings.Parse(json);

        Assert.Equal(family, settings.FontFamily);
        Assert.Equal(size, settings.FontSize);
    }

    [Fact]
    public void WslIsNeverFoundThroughTheSearchPath()
    {
        Assert.True(Path.IsPathRooted(LaunchPlan.Executable));
        Assert.EndsWith("wsl.exe", LaunchPlan.Executable);
    }

    [Fact]
    public void TokensAreWhatPortaInsistsOn()
    {
        Assert.Matches("^[0-9a-f]{32}$", LaunchPlan.NewToken());
    }

    private static JsonElement FontChoice([CallerFilePath] string here = "") =>
        JsonDocument.Parse(File.ReadAllText(Path.Combine(Path.GetDirectoryName(here)!, "..", "..", "contract", "font-choice.json"))).RootElement;

    [Fact]
    public void AChosenSizeIsWrittenAsTheContractSays()
    {
        foreach (var pair in FontChoice().GetProperty("sizes").EnumerateArray())
        {
            var chosen = double.Parse(pair[0].GetString()!, System.Globalization.CultureInfo.InvariantCulture);
            var written = double.Parse(pair[1].GetString()!, System.Globalization.CultureInfo.InvariantCulture);
            Assert.Equal(written, DesktopSettings.WrittenSize(chosen));
        }
    }

    [Fact]
    public void AChosenFontKeepsTheRestOfTheFile()
    {
        var written = DesktopSettings.Written(FontChoice().GetProperty("existing").GetString(), "Cascadia \"Mono\" \\ Pro", 14.6);

        var read = DesktopSettings.Parse(written);
        Assert.Equal("Cascadia \"Mono\" \\ Pro", read.FontFamily);
        Assert.Equal(15, read.FontSize);
        var other = JsonDocument.Parse(written).RootElement.GetProperty("other");
        Assert.Equal(1, other[0].GetInt32());
        Assert.True(other[1].GetProperty("deep").GetBoolean());
    }

    [Theory]
    [InlineData(null)]
    [InlineData("not json")]
    [InlineData("[1]")]
    public void AMissingOrBrokenFileIsReplacedWithTheChosenFont(string? existing)
    {
        Assert.Equal("Menlo", DesktopSettings.Parse(DesktopSettings.Written(existing, "Menlo", 13)).FontFamily);
    }

    [Theory]
    // A Windows that never had WSL: the window offers to install it, as an administrator.
    [InlineData(false, 0, WslNeed.Wsl)]
    // What a first `wsl --install` left on Windows on Arm, after the restart it asked for:
    // offering to install WSL again would go round in circles.
    [InlineData(true, 0, WslNeed.Distribution)]
    // A distribution that does not answer: installing either again would not help.
    [InlineData(true, 1, WslNeed.Start)]
    public void TheWindowOffersWhatIsMissing(bool installed, int distributions, WslNeed need)
    {
        Assert.Equal(need, WslCheck.Of(installed, distributions));
    }

    [Fact]
    public void WslsOwnWordsAreShownWithoutItsUtf16Padding()
    {
        // wsl.exe's answer to `--exec uname -m` without a distribution, as UTF-16 read as UTF-8.
        var said = string.Join("\0", "Windows Subsystem for Linux has no installed distributions.\r\n\r\nUse 'wsl.exe --list --online' to list available distributions\r\n".ToCharArray());

        Assert.Equal(
            "Windows Subsystem for Linux has no installed distributions.\nUse 'wsl.exe --list --online' to list available distributions",
            WslCheck.Reason(said));
    }

    [Fact]
    public void ALongAnswerKeepsItsLastLines()
    {
        Assert.Equal("15\n16\n17\n18\n19\n20", WslCheck.Reason(string.Join("\n", Enumerable.Range(1, 20))));
    }

    [Fact]
    public void AFailedInstallShowsItsErrorRatherThanItsProgress()
    {
        // `wsl --install --no-launch` on Windows on Arm without virtualization, as a VM gives it.
        var said = "Downloading: Ubuntu\nInstalling: Ubuntu\n"
            + "WSL2 is unable to start since virtualization is not enabled on this machine.\n"
            + "Please ensure the \"Virtual Machine Platform\" optional component is enabled and virtualization is turned on in your computer's firmware settings.\n\n"
            + "Enable \"Virtual Machine Platform\" by running: wsl.exe --install --no-distribution\n\n"
            + "For information please visit https://aka.ms/enablevirtualization\n"
            + "Error code: Wsl/InstallDistro/Service/RegisterDistro/CreateVm/HCS/HCS_E_HYPERV_NOT_INSTALLED\n"
            + "If this error was unexpected, please consider searching for existing issues or filing a new issue at https://github.com/microsoft/WSL/issues.\n";

        var reason = WslCheck.Reason(said);

        Assert.StartsWith("WSL2 is unable to start", reason);
        Assert.Contains("HCS_E_HYPERV_NOT_INSTALLED", reason);
    }
}
