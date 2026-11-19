"""Draws the disk image's background: the application, an arrow, the Applications folder.

Run once after a change, from the repository root: python3 desktop/macos/dmg-background.py
It writes desktop/macos/Resources/dmg-background.tiff, 1x and 2x in one file.
Positions match desktop/macos/dmg-settings.py.
"""

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

    pen = ImageDraw.Draw(image)
    start, end = (APP + 88) * scale, (FOLDER - 88) * scale
    y = ROW * scale
    # A gentle arc, dashed, then a solid head.
    points = []
    for i in range(41):
        t = i / 40
        points.append((start + (end - start) * t, y - 26 * scale * 4 * t * (1 - t)))
    for i in range(0, 40, 2):
        pen.line([points[i], points[i + 1]], fill=INDIGO, width=5 * scale)
    tip = points[-1]
    pen.polygon([
        (tip[0] + 6 * scale, tip[1] + 1 * scale),
        (tip[0] - 14 * scale, tip[1] - 13 * scale),
        (tip[0] - 10 * scale, tip[1] + 13 * scale),
    ], fill=INDIGO)

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
