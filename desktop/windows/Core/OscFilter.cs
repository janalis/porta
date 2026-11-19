using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text;

namespace Porta.Desktop
{
    /// <summary>Takes Porta's private sequences and clipboard writes out of the output, wherever the reads split them.</summary>
    public sealed class OscFilter
    {
        public const int Private = 7777;
        public const int Clipboard = 52;

        private const byte Escape = 0x1b;
        private const byte Bell = 0x07;
        private const int LongestPayload = 4 * 1024 * 1024;

        private static readonly (int Code, byte[] Prefix)[] Prefixes =
        {
            (Private, Encoding.ASCII.GetBytes("\u001b]7777;")),
            (Clipboard, Encoding.ASCII.GetBytes("\u001b]52;")),
        };

        private readonly List<byte> held = new List<byte>();
        private readonly MemoryStream payload = new MemoryStream();
        private int code;
        private bool sawEscape;
        private List<byte>? foreign;

        public byte[] Consume(byte[] bytes, int count, IList<(int Code, string Payload)> found)
        {
            var output = new MemoryStream(count);
            for (var i = 0; i < count; i++)
            {
                if (code != 0)
                {
                    ReadPayload(bytes[i], output, found);
                }
                else
                {
                    MatchPrefix(bytes[i], output);
                }
            }

            return output.ToArray();
        }

        private void MatchPrefix(byte value, MemoryStream output)
        {
            held.Add(value);
            foreach (var (candidate, prefix) in Prefixes)
            {
                if (held.Count <= prefix.Length && held.SequenceEqual(prefix.Take(held.Count)))
                {
                    if (held.Count == prefix.Length)
                    {
                        code = candidate;
                        held.Clear();
                    }

                    return;
                }
            }

            output.Write(held.ToArray(), 0, held.Count - 1);
            held.Clear();
            if (value == Escape)
            {
                held.Add(value);
            }
            else
            {
                output.WriteByte(value);
            }
        }

        private void ReadPayload(byte value, MemoryStream output, IList<(int Code, string Payload)> found)
        {
            if (foreign != null)
            {
                // A control sequence runs to its final byte, 0x40 to 0x7E.
                foreign.Add(value);
                if (value >= 0x40 && value <= 0x7E)
                {
                    output.Write(foreign.ToArray(), 0, foreign.Count);
                    foreign = null;
                }

                return;
            }

            if (value == Bell || (sawEscape && value == (byte)'\\'))
            {
                found.Add((code, Encoding.UTF8.GetString(payload.ToArray())));
                Reset();
                return;
            }

            if (sawEscape)
            {
                // Porta's payloads never hold ESC: this is another writer's sequence that
                // landed mid-payload (symfony/tui answers the kitty query unqueued).
                sawEscape = false;
                if (value == (byte)'[')
                {
                    foreign = new List<byte> { Escape, value };
                }
                else
                {
                    output.WriteByte(Escape);
                    output.WriteByte(value);
                }

                return;
            }

            if (value == Escape)
            {
                sawEscape = true;
                return;
            }

            payload.WriteByte(value);
            if (payload.Length > LongestPayload)
            {
                // Not ours after all: nothing Porta sends is this long.
                var prefix = Prefixes.First(entry => entry.Code == code).Prefix;
                output.Write(prefix, 0, prefix.Length);
                payload.WriteTo(output);
                Reset();
            }
        }

        private void Reset()
        {
            code = 0;
            sawEscape = false;
            foreign = null;
            payload.SetLength(0);
        }
    }
}
