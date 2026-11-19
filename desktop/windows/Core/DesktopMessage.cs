using System;
using System.Linq;
using System.Text;

namespace Porta.Desktop
{
    public enum MessageKind
    {
        None,
        Ready,
        Accepted,
        Closing,

        /// <summary>How many files are unsaved, in the second field.</summary>
        State,
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
                case "state" when fields.Length == 4 && fields[3].Length > 0 && fields[3].All(char.IsDigit):
                    return (MessageKind.State, fields[3]);
                case "ready" when fields.Length == 4:
                    return (MessageKind.Ready, Encoding.UTF8.GetString(Convert.FromBase64String(fields[3])));
                default:
                    return (MessageKind.None, "");
            }
        }

        public static string CloseRequest(string token) => $"\u001b]{OscFilter.Private};{Namespace};{token};close\u001b\\";

        /// <summary>A file or folder the system handed the application, for the window that has its project.</summary>
        public static string OpenRequest(string token, string path) =>
            $"\u001b]{OscFilter.Private};{Namespace};{token};open;{Convert.ToBase64String(Encoding.UTF8.GetBytes(path))}\u001b\\";

        /// <summary>The system's light or dark mode, followed while no theme was chosen in Porta.</summary>
        public static string Appearance(string token, bool dark) =>
            $"\u001b]{OscFilter.Private};{Namespace};{token};appearance;{(dark ? "dark" : "light")}\u001b\\";
    }
}
