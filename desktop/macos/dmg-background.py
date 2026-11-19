"""Draws the disk image's background: the application, an arrow, the Applications folder.

Run once after a change, from the repository root: python3 desktop/macos/dmg-background.py
It writes desktop/macos/Resources/dmg-background.tiff, 1x and 2x in one file.
Positions match desktop/macos/dmg-settings.py.
"""

import math
import subprocess
import tempfile
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

WIDTH, HEIGHT = 660, 400
APP, FOLDER, ROW = 170, 490, 150
INDIGO = (54, 50, 140)
INK = (40, 38, 70)


def font(size: int) -> ImageFont.FreeTypeFont:
    for path in ['/System/Library/Fonts/SFNS.ttf', '/System/Library/Fonts/Helvetica.ttc']:
        try:
            return ImageFont.truetype(path, size)
        except OSError:
            continue
    return ImageFont.load_default(size)


def arrow(scale: int) -> Image.Image:
    """A smooth arc ending in a chevron that follows it, drawn large and scaled down so its edges are soft."""
    big = scale * 4
    layer = Image.new('RGBA', (WIDTH * big, HEIGHT * big), (0, 0, 0, 0))
    pen = ImageDraw.Draw(layer)
    start, end, y = (APP + 90) * big, (FOLDER - 96) * big, ROW * big
    lift = 30 * big
    points = [(start + (end - start) * t, y - lift * 4 * t * (1 - t)) for t in (i / 60 for i in range(61))]
    stroke = 5 * big
    pen.line(points, fill=INDIGO, width=stroke, joint='curve')
    for x, y_ in (points[0], points[-1]):
        pen.ellipse([x - stroke / 2, y_ - stroke / 2, x + stroke / 2, y_ + stroke / 2], fill=INDIGO)
    # The head follows the arc's last direction.
    (x1, y1), (x2, y2) = points[-4], points[-1]
    angle = math.atan2(y2 - y1, x2 - x1)
    length, spread = 18 * big, math.radians(32)
    for side in (-1, 1):
        wing = (x2 - length * math.cos(angle + side * spread), y2 - length * math.sin(angle + side * spread))
        pen.line([wing, (x2, y2)], fill=INDIGO, width=stroke)
        pen.ellipse([wing[0] - stroke / 2, wing[1] - stroke / 2, wing[0] + stroke / 2, wing[1] + stroke / 2], fill=INDIGO)
    pen.ellipse([x2 - stroke / 2, y2 - stroke / 2, x2 + stroke / 2, y2 + stroke / 2], fill=INDIGO)
    return layer.resize((WIDTH * scale, HEIGHT * scale), Image.LANCZOS)


def draw(scale: int) -> Image.Image:
    width, height = WIDTH * scale, HEIGHT * scale
    image = Image.new('RGB', (width, height))
    pixels = image.load()
    # A soft wash, light enough that Finder's black labels stay readable.
    for y in range(height):
        for x in range(width):
            t = (x / width) * 0.6 + (y / height) * 0.4
            pixels[x, y] = (int(248 - 14 * t), int(247 - 12 * t), int(255 - 4 * t))

    glow = Image.new('L', (width, height), 0)
    ImageDraw.Draw(glow).ellipse(
        [(APP - 95) * scale, (ROW - 95) * scale, (FOLDER + 95) * scale, (ROW + 95) * scale], fill=70)
    glow = glow.filter(ImageFilter.GaussianBlur(40 * scale))
    image = Image.composite(Image.new('RGB', (width, height), (232, 230, 252)), image, glow)

    image.paste(arrow(scale), (0, 0), arrow(scale))
    pen = ImageDraw.Draw(image)

    caption = 'Drag Porta to Applications to install it'
    face = font(17 * scale)
    box = pen.textbbox((0, 0), caption, font=face)
    pen.text(((width - (box[2] - box[0])) / 2, 262 * scale), caption, fill=INK, font=face)
    return image


def main() -> None:
    target = Path('desktop/macos/Resources/dmg-background.tiff')
    with tempfile.TemporaryDirectory() as work:
        normal, retina = Path(work, 'background.png'), Path(work, 'background@2x.png')
        draw(1).save(normal, dpi=(72, 72))
        draw(2).save(retina, dpi=(144, 144))
        subprocess.run(['tiffutil', '-cathidpicheck', str(normal), str(retina), '-out', str(target)], check=True)
    print(target)


if __name__ == '__main__':
    main()
