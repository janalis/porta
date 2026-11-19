using System;
using System.Globalization;
using System.IO;
using System.Runtime.Serialization;
using System.Runtime.Serialization.Json;
using System.Text;
using System.Xml;
using System.Xml.Linq;

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

        /// <summary>A chosen size as the file holds it: whole points, within what a window reads (contract/font-choice.json).</summary>
        public static double WrittenSize(double chosen) =>
            Math.Min(LargestFont, Math.Max(SmallestFont, Math.Round(chosen, MidpointRounding.AwayFromZero)));

        /// <summary>The file with a font chosen in the window: the two keys set, anything else in it kept.</summary>
        public static string Written(string? existing, string fontFamily, double fontSize)
        {
            // JSON read as the XML the framework maps it to, which keeps every key whatever it holds.
            var root = ReadAsXml(existing);
            Set(root, "fontFamily", "string", fontFamily);
            Set(root, "fontSize", "number", WrittenSize(fontSize).ToString(CultureInfo.InvariantCulture));
            using var stream = new MemoryStream();
            using (var writer = JsonReaderWriterFactory.CreateJsonWriter(stream, Encoding.UTF8, ownsStream: false, indent: true))
            {
                root.WriteTo(writer);
            }

            return Encoding.UTF8.GetString(stream.ToArray());
        }

        private static XElement ReadAsXml(string? json)
        {
            if (!string.IsNullOrWhiteSpace(json))
            {
                try
                {
                    using var reader = JsonReaderWriterFactory.CreateJsonReader(Encoding.UTF8.GetBytes(json), XmlDictionaryReaderQuotas.Max);
                    var root = XElement.Load(reader);
                    if ((string?)root.Attribute("type") == "object")
                    {
                        return root;
                    }
                }
                catch (XmlException)
                {
                    // Not JSON: replaced by the font alone.
                }
                catch (SerializationException)
                {
                    // Likewise.
                }
            }

            return new XElement("root", new XAttribute("type", "object"));
        }

        private static void Set(XElement root, string key, string type, string value)
        {
            root.Element(key)?.Remove();
            root.Add(new XElement(key, new XAttribute("type", type), value));
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
