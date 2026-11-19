using System;
using System.Text;

namespace Porta.Desktop
{
    public enum MessageKind
    {
        None,
        Ready,
        Accepted,
        Closing,
    }

    /// <summary>The conversation with Porta, as desktop/contract/protocol.json defines it.</summary>
    public static class DesktopMessage
    {
        private const string Namespace = "porta-desktop";

        public static (MessageKind Kind, string Root) Parse(string payload, string token)
        {
            var fields = payload.Split(';');
            if (fields.Length < 3 || fields[0] != Namespace || fields[1] != token)
            {
                return (MessageKind.None, "");
            }

            switch (fields[2])
            {
                case "accepted" when fields.Length == 3:
                    return (MessageKind.Accepted, "");
                case "closing" when fields.Length == 3:
                    return (MessageKind.Closing, "");
                case "ready" when fields.Length == 4:
                    return (MessageKind.Ready, Encoding.UTF8.GetString(Convert.FromBase64String(fields[3])));
                default:
                    return (MessageKind.None, "");
            }
        }

        public static string CloseRequest(string token) => $"\u001b]{OscFilter.Private};{Namespace};{token};close\u001b\\";
    }
}
