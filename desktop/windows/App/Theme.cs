using Microsoft.Terminal.Wpf;

namespace Porta.Desktop.App
{
    internal static class Theme
    {
        /// <summary>Colours are 0x00BBGGRR. Porta's own theme paints over these almost everywhere.</summary>
        public static readonly TerminalTheme Dark = new TerminalTheme
        {
            DefaultBackground = 0x000000,
            DefaultForeground = 0xCCCCCC,
            DefaultSelectionBackground = 0x664433,
            CursorStyle = CursorStyle.BlinkingBar,
            ColorTable = new uint[]
            {
                0x000000, 0x3131CD, 0x79BC0D, 0x10E5E5, 0xC87224, 0xBC3FBC, 0xCDA811, 0xE5E5E5,
                0x666666, 0x4C4CF1, 0x8BD123, 0x43F5F5, 0xEA8E3B, 0xD670D6, 0xDBB829, 0xFFFFFF,
            },
        };
    }
}
