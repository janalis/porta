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

        public double? FontSize
        {
            get
            {
                // The serializer gives a number back as int, long, double or decimal, whichever fits.
                double? points = size switch
                {
                    int whole => whole,
                    long whole => whole,
                    double real => real,
                    decimal exact => (double)exact,
                    _ => null,
                };
                return points >= SmallestFont && points <= LargestFont ? points : null;
            }
        }

        /// <summary>The file with a font chosen in the window: the two keys it holds.</summary>
        public static string Written(string fontFamily, double fontSize)
        {
            var settings = new DesktopSettings { family = fontFamily, size = fontSize };
            using var stream = new MemoryStream();
            new DataContractJsonSerializer(typeof(DesktopSettings)).WriteObject(stream, settings);
            return Encoding.UTF8.GetString(stream.ToArray());
        }

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
