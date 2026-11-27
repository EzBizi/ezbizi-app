"""EzChat's app icon: the EzBizi icon in green (Jose 11-25: "a green version
of the icon, like we had the red and purple ones for whatsapp").

    python scripts/ezchat-icons.py [#RRGGBB]

Recolours assets/logo/app-icon-1024.png (cyan #00AEEF behind a white E and
a black z) by moving every coloured pixel, the shadows included, to the new
hue at the same relative strength, then writes every size each platform
asks for under assets/logo/ezchat/:
  ios/AppIcon.appiconset   the files and Contents.json Flutter's Runner uses
  macos/AppIcon.appiconset the same, for macOS
  android/res              legacy ic_launcher.png per density, and the
                           adaptive pair (foreground glyph on transparent,
                           background colour) under mipmap-anydpi-v26
  ezchat-1024.png, ezchat-512.png (stores)
With no colour it draws the recommended green, and the three gallery
options to assets/logo/ezchat/options/.
"""
import colorsys
import json
import os
import sys

import numpy as np
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'assets', 'logo', 'app-icon-1024.png')
OUT = os.path.join(ROOT, 'assets', 'logo', 'ezchat')
CYAN = (0x00, 0xAE, 0xEF)
OPTIONS = {'1': '#00C875', '2': '#0A8F4E', '3': '#00A884'}
RECOMMENDED = OPTIONS['1']


def rgb(hexa):
    h = hexa.lstrip('#')
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def recolour(target):
    """The icon with its cyan moved to [target]; white and black untouched."""
    im = np.asarray(Image.open(SRC).convert('RGB')).astype(np.float64) / 255
    r, g, b = im[..., 0], im[..., 1], im[..., 2]
    mx, mn = im.max(-1), im.min(-1)
    sat = np.where(mx > 0, (mx - mn) / np.maximum(mx, 1e-9), 0)
    _, s0, v0 = colorsys.rgb_to_hsv(*(c / 255 for c in CYAN))
    th, ts, tv = colorsys.rgb_to_hsv(*(c / 255 for c in rgb(target)))
    out = im.copy()
    coloured = sat > 0.15  # the background and its shadows, not E or z
    s = np.clip(sat * ts / s0, 0, 1)
    v = np.clip(mx * tv / v0, 0, 1)
    # HSV -> RGB at one hue for the whole image.
    i = int(th * 6) % 6
    f = th * 6 - int(th * 6)
    p, q, t = v * (1 - s), v * (1 - s * f), v * (1 - s * (1 - f))
    chans = [(v, t, p), (q, v, p), (p, v, t), (p, q, v), (t, p, v), (v, p, q)][i]
    for c in range(3):
        out[..., c] = np.where(coloured, chans[c], im[..., c])
    return Image.fromarray((out * 255).round().astype(np.uint8))


def glyph(icon, target):
    """The E and z with their shadow on transparent: each pixel is read as
    white or black laid over the background at some opacity."""
    a = np.asarray(icon).astype(np.float64) / 255
    bg = np.array(rgb(target)) / 255
    # Towards white: p = bg + a*(1-bg); towards black: p = bg*(1-a).
    up = ((a - bg) * (1 - bg)).sum(-1) / max(((1 - bg) ** 2).sum(), 1e-9)
    down = ((bg - a) * bg).sum(-1) / max((bg ** 2).sum(), 1e-9)
    white = up > down
    alpha = np.clip(np.where(white, up, down), 0, 1)
    alpha[alpha < 0.02] = 0
    rgba = np.zeros(a.shape[:2] + (4,))
    rgba[..., :3] = np.where(white[..., None], 1.0, 0.0)
    rgba[..., 3] = alpha
    return Image.fromarray((rgba * 255).round().astype(np.uint8))


def sized(im, px):
    return im.resize((px, px), Image.LANCZOS)


def write_ios(icon, folder):
    os.makedirs(folder, exist_ok=True)
    images = []
    for idiom, pt, scales in [
        ('iphone', 20, [2, 3]), ('iphone', 29, [1, 2, 3]),
        ('iphone', 40, [2, 3]), ('iphone', 60, [2, 3]),
        ('ipad', 20, [1, 2]), ('ipad', 29, [1, 2]), ('ipad', 40, [1, 2]),
        ('ipad', 76, [1, 2]), ('ipad', 83.5, [2]),
        ('ios-marketing', 1024, [1]),
    ]:
        for sc in scales:
            name = f'Icon-App-{pt:g}x{pt:g}@{sc}x.png'
            sized(icon, round(pt * sc)).save(os.path.join(folder, name))
            images.append({'size': f'{pt:g}x{pt:g}', 'idiom': idiom,
                           'filename': name, 'scale': f'{sc}x'})
    json.dump({'images': images, 'info': {'version': 1, 'author': 'xcode'}},
              open(os.path.join(folder, 'Contents.json'), 'w'), indent=2)


def write_macos(icon, folder):
    os.makedirs(folder, exist_ok=True)
    images = []
    for pt in [16, 32, 128, 256, 512]:
        for sc in [1, 2]:
            px = pt * sc
            name = f'app_icon_{px}.png'
            sized(icon.convert('RGBA'), px).save(os.path.join(folder, name))
            images.append({'size': f'{pt}x{pt}', 'idiom': 'mac',
                           'filename': name, 'scale': f'{sc}x'})
    json.dump({'images': images, 'info': {'version': 1, 'author': 'xcode'}},
              open(os.path.join(folder, 'Contents.json'), 'w'), indent=2)


def write_android(icon, fg, target, res):
    dens = {'mdpi': 1, 'hdpi': 1.5, 'xhdpi': 2, 'xxhdpi': 3, 'xxxhdpi': 4}
    for d, k in dens.items():
        folder = os.path.join(res, f'mipmap-{d}')
        os.makedirs(folder, exist_ok=True)
        sized(icon, round(48 * k)).save(os.path.join(folder, 'ic_launcher.png'))
        # Adaptive: a 108dp layer of which the middle 72dp shows, and the
        # launcher's mask may cut into that; the glyph sits in the 66dp safe
        # circle (at 72dp its corners reach 33dp out, so 64).
        side = round(108 * k)
        layer = Image.new('RGBA', (side, side), (0, 0, 0, 0))
        g = sized(fg, round(64 * k))  # corners inside the 66dp circle
        layer.paste(g, ((side - g.width) // 2, (side - g.height) // 2), g)
        layer.save(os.path.join(folder, 'ic_launcher_foreground.png'))
    any_dpi = os.path.join(res, 'mipmap-anydpi-v26')
    os.makedirs(any_dpi, exist_ok=True)
    open(os.path.join(any_dpi, 'ic_launcher.xml'), 'w').write(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <background android:drawable="@color/ic_launcher_background"/>\n'
        '    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>\n'
        '    <monochrome android:drawable="@mipmap/ic_launcher_foreground"/>\n'
        '</adaptive-icon>\n')
    values = os.path.join(res, 'values')
    os.makedirs(values, exist_ok=True)
    open(os.path.join(values, 'ic_launcher_background.xml'), 'w').write(
        '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
        f'    <color name="ic_launcher_background">{target.upper()}</color>\n'
        '</resources>\n')


def main():
    target = sys.argv[1] if len(sys.argv) > 1 else RECOMMENDED
    if len(sys.argv) == 1:
        opts = os.path.join(OUT, 'options')
        os.makedirs(opts, exist_ok=True)
        for k, c in OPTIONS.items():
            sized(recolour(c), 512).save(os.path.join(opts, f'ezchat-green-{k}.png'))
    icon = recolour(target)
    fg = glyph(icon, target)
    os.makedirs(OUT, exist_ok=True)
    icon.save(os.path.join(OUT, 'ezchat-1024.png'))
    sized(icon, 512).save(os.path.join(OUT, 'ezchat-512.png'))
    write_ios(icon, os.path.join(OUT, 'ios', 'AppIcon.appiconset'))
    write_macos(icon, os.path.join(OUT, 'macos', 'AppIcon.appiconset'))
    write_android(icon, fg, target, os.path.join(OUT, 'android', 'res'))
    print('EzChat icon', target, '->', OUT)


if __name__ == '__main__':
    main()
