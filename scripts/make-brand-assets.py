#!/usr/bin/env python3
"""
Regenerate every derived brand asset from the two source files.

    python scripts/make-brand-assets.py

Sources, both committed:

    assets/logo/app-icon-source.png   the square mark, as drawn
    assets/logo/logo.png              the wordmark, black on white

Everything else in assets/logo, assets/installer, client/android/.../mipmap-*,
client/web/icons and www/ is derived from those two and should never be edited
by hand — an edit there is lost the next time this runs.

── Why the source needs fixing at all ──────────────────────────────

The square mark was drawn with rounded corners over BLACK, so the four corners
are opaque black rather than transparent. Two things follow:

  · Google Play and every Android launcher apply their OWN rounded mask. An
    icon that arrives already rounded gets rounded twice and the black corners
    survive as dark slivers inside the new mask.
  · The corner black is anti-aliased into the cyan, so it cannot simply be
    keyed out — a soft mask blends the black with whatever fills behind it and
    puts the dark arcs straight back. The cut has to be HARD, and placed far
    enough inside that both sides of it are already the same cyan, which is
    what makes it invisible.

The background also reads (0,170,242) rather than brand (0,174,239), because
the source is a screenshot. That is snapped here rather than in the source, so
the source stays exactly what was drawn.
"""

from pathlib import Path
import numpy as np
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
BRAND = (0, 174, 239)
SCREENSHOT_CYAN = (0, 170, 242)
SS = 4  # supersample, so mask edges are not stair-stepped


def snapped_source() -> Image.Image:
    """The square mark, cropped square and shifted onto the brand cyan."""
    src = Image.open(ROOT / "assets/logo/app-icon-source.png").convert("RGBA")
    w, h = src.size
    side = min(w, h)
    src = src.crop(((w - side) // 2, (h - side) // 2, (w - side) // 2 + side, (h - side) // 2 + side))

    a = np.array(src).astype(int)
    rgb = a[:, :, :3]
    # Everything blue-dominant and bright enough is background or its shading;
    # the white E and black Z are untouched, so their edges stay clean.
    cyanish = (rgb[:, :, 2] > rgb[:, :, 0] + 40) & (rgb[:, :, 1] > 90)
    rgb[cyanish] = np.clip(rgb[cyanish] + (np.array(BRAND) - np.array(SCREENSHOT_CYAN)), 0, 255)
    a[:, :, :3] = rgb
    return Image.fromarray(a.astype("uint8"))


def rounded_mask(size: int, radius: int, inset: int) -> np.ndarray:
    """Inset and radius shrink together, or the arc centre moves outward."""
    m = Image.new("L", (size * SS, size * SS), 0)
    ImageDraw.Draw(m).rounded_rectangle(
        [inset * SS, inset * SS, (size - inset) * SS - 1, (size - inset) * SS - 1],
        radius=max(1, radius - inset) * SS,
        fill=255,
    )
    return np.array(m.resize((size, size), Image.LANCZOS))


def wordmark_white() -> Image.Image:
    """The wordmark as white-on-transparent, minus the '.com' line beneath it."""
    src = Image.open(ROOT / "assets/logo/logo.png").convert("RGB")
    a = np.array(src.crop((0, 0, src.width, 600))).astype(int)
    alpha = np.where(a.sum(axis=2) < 700, 255, 0).astype("uint8")
    im = Image.fromarray(np.dstack([np.full(alpha.shape, 255, "uint8")] * 3 + [alpha]))
    return im.crop(im.getbbox())


def main() -> None:
    src = snapped_source()
    side = src.width
    radius = round(side * 0.1192)  # measured: the corner black runs ~11.9% of the edge

    # A HARD cut, well inside the solid cyan. See the module docstring.
    #
    # The inset is measured, not guessed. Walking in along the corner diagonal,
    # the source is black until y=x=42 and clean cyan from y=x=45 — the rounded
    # rect's arc AND the soft drop shadow around it both live in there. An inset
    # of 12 put the cut at y=x=42.2, exactly on the transition, which is why a
    # faint dark arc survived at each corner. 30 lands it around y=x=55: about
    # thirteen pixels clear of anything that is not flat cyan.
    keep = rounded_mask(side, radius, inset=30) >= 128
    flat = np.array(src)[:, :, :3].copy()
    flat[~keep] = BRAND
    square = Image.fromarray(flat.astype("uint8"), "RGB")

    out = lambda p: (ROOT / p)
    (ROOT / "assets/installer").mkdir(parents=True, exist_ok=True)

    # 1. The icon everything else is cut from: full-bleed, no rounding, no shadow.
    square.resize((512, 512), Image.LANCZOS).save(out("assets/logo/app-icon-512.png"), optimize=True)

    # 2. A rounded variant for surfaces that do NOT apply their own mask.
    alpha = rounded_mask(side, radius, inset=0)
    Image.fromarray(np.dstack([np.array(square), alpha]).astype("uint8"), "RGBA") \
        .resize((512, 512), Image.LANCZOS) \
        .save(out("assets/logo/app-icon-rounded-512.png"), optimize=True)

    base = Image.open(out("assets/logo/app-icon-512.png")).convert("RGBA")

    # 3. Windows wants an .ico with several sizes inside it; the shell picks per
    #    context — 16px in the title bar, 256px in the Programs list.
    base.save(out("assets/installer/ezbizi.ico"),
              sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])

    # 4. Android launcher densities.
    for name, px in [("mdpi", 48), ("hdpi", 72), ("xhdpi", 96), ("xxhdpi", 144), ("xxxhdpi", 192)]:
        base.resize((px, px), Image.LANCZOS).save(
            out(f"client/android/app/src/main/res/mipmap-{name}/ic_launcher.png"))

    # 5. Web app icons and favicons.
    for px in (192, 512):
        base.resize((px, px), Image.LANCZOS).save(out(f"client/web/icons/Icon-{px}.png"))
        base.resize((px, px), Image.LANCZOS).save(out(f"client/web/icons/Icon-maskable-{px}.png"))
    base.resize((64, 64), Image.LANCZOS).save(out("client/web/favicon.png"))
    base.resize((512, 512), Image.LANCZOS).convert("RGB").save(out("www/icon-512.png"), optimize=True)
    base.resize((180, 180), Image.LANCZOS).convert("RGB").save(out("www/apple-touch-icon.png"), optimize=True)
    #    Six frames, not three. A 48px ceiling is fine in a tab and poor everywhere
    #    else a favicon is used — bookmarks bar, a pinned tab, a desktop shortcut,
    #    the browser's own history UI — which fall back to upscaling 48px.
    #    The live site has carried the six-frame version since 26 August; this line
    #    still wrote three, so ANY run of this script silently reverted it, and
    #    `deploy-www.sh` would then publish the revert. The docstring above tells
    #    people www/ is derived and safe to regenerate, which is what made that a
    #    trap rather than a mistake. `www/check-site.py` now fails if the 256px
    #    frame goes missing again.
    base.save(out("www/favicon.ico"),
              sizes=[(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])

    # 6. Play feature graphic.
    feature = Image.new("RGB", (1024, 500), BRAND)
    d = ImageDraw.Draw(feature)
    font = ImageFont.truetype("C:/Windows/Fonts/segoeuib.ttf", 30)
    tag = "Field-service CRM for multi-location operations"
    tb = d.textbbox((0, 0), tag, font=font)
    wm = wordmark_white()
    wm = wm.resize((460, round(wm.height * 460 / wm.width)), Image.LANCZOS)
    gap, th = 46, tb[3] - tb[1]
    top = (500 - (wm.height + gap + th)) // 2
    feature.paste(wm, ((1024 - wm.width) // 2, top), wm)
    d.text(((1024 - (tb[2] - tb[0])) // 2, top + wm.height + gap - tb[1]), tag, font=font, fill="white")
    feature.save(out("assets/logo/feature-graphic-1024x500.png"), optimize=True)

    # 7. Inno Setup wizard art. BMP because that is all Inno reads, 24-bit
    #    because some Inno versions mis-handle 32-bit BMPs with alpha.
    #
    #    Several sizes per image, comma-separated in the .iss, so Inno picks the
    #    right one for the display's DPI instead of scaling a small one up.
    for scale in (1, 2, 3):
        w, h = 164 * scale, 314 * scale
        panel = Image.new("RGB", (w, h), BRAND)
        mark = base.resize((round(96 * scale), round(96 * scale)), Image.LANCZOS)
        panel.paste(mark, ((w - mark.width) // 2, round(64 * scale)), mark)
        word = wordmark_white()
        tw = round(112 * scale)
        word = word.resize((tw, round(word.height * tw / word.width)), Image.LANCZOS)
        panel.paste(word, ((w - tw) // 2, round(190 * scale)), word)
        panel.save(out(f"assets/installer/wizard-large-{scale}x.bmp"))

        sw, sh = 55 * scale, 58 * scale
        small = Image.new("RGB", (sw, sh), (255, 255, 255))
        m = base.resize((round(44 * scale), round(44 * scale)), Image.LANCZOS)
        small.paste(m, ((sw - m.width) // 2, (sh - m.height) // 2), m)
        small.save(out(f"assets/installer/wizard-small-{scale}x.bmp"))

    print("brand assets regenerated from assets/logo/app-icon-source.png + logo.png")


if __name__ == "__main__":
    main()
