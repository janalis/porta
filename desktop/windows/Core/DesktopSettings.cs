using System.IO;
using System.Runtime.Serialization;
using System.Runtime.Serialization.Json;
using System.Text;

namespace Porta.Desktop
{
    /// <summary>The window's own settings, from desktop.json. Anything unusable reads as not set.</summary>
    [DataContract]
    public sealed class DesktopSettings
    {
        private const double SmallestFont = 6;
        private const double LargestFont = 72;

        [DataMember(Name = "fontFamily")]
        private object? family;

        [DataMember(Name = "fontSize")]
        private object? size;

        public string? FontFamily => family is string name && name.Length > 0 ? name : null;

        public double? FontSize => size is double points && points >= SmallestFont && points <= LargestFont
            ? points
            : size is int whole && whole >= SmallestFont && whole <= LargestFont ? whole : (double?)null;

        public static DesktopSettings Parse(string? json)
        {
            if (string.IsNullOrWhiteSpace(json))
            {
                return new DesktopSettings();
            }

            try
            {
                using var stream = new MemoryStream(Encoding.UTF8.GetBytes(json));
                var serializer = new DataContractJsonSerializer(typeof(DesktopSettings));
                return serializer.ReadObject(stream) as DesktopSettings ?? new DesktopSettings();
            }
            catch (SerializationException)
            {
                return new DesktopSettings();
            }
        }
    }
}
