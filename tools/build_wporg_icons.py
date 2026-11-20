#!/usr/bin/env python3
"""Build WordPress.org icon fallbacks from the canonical Dendrila Privacy SVG."""
from pathlib import Path
import hashlib
import struct

import cairosvg

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "assets" / "logo-mark.svg"
ASSETS = ROOT / ".wordpress-org"

def main():
    svg = SRC.read_bytes()
    if (ASSETS / "icon.svg").read_bytes() != svg:
        raise SystemExit("WP.org icon.svg differs from the logo already shown in the plugin.")
    for size in (128, 256):
        png = cairosvg.svg2png(bytestring=svg, output_width=size, output_height=size)
        if not png.startswith(bytes.fromhex("89504e470d0a1a0a")):
            raise SystemExit("Not a PNG")
        width, height = struct.unpack(">II", png[16:24])
        if (width, height) != (size, size):
            raise SystemExit("Bad output dimensions")
        target = ASSETS / "icon-{}x{}.png".format(size, size)
        target.write_bytes(png)
        print("{}: {} bytes, sha256 {}".format(target.relative_to(ROOT), len(png), hashlib.sha256(png).hexdigest()))

if __name__ == "__main__":
    main()
