#!/usr/bin/env python3
"""Builds the wordpress.org listing artwork -- the plugin icon and the page banner.

    python3 tools/build-brand.py

Writes SVG sources and the PNG sizes the directory serves into .wordpress-org/. None of it
ships in the plugin zip: the directory reads assets/ at the root of the SVN checkout, which
is a different place from the plugin's own assets/, and .distignore excludes this directory.

The mark is not a picture of a justified grid, it is one. Every tile's width is its aspect
ratio times a row height shared by the whole row, which is the rule Lichtbild_Renderer
implements in CSS -- so editing an aspect ratio below re-solves the geometry the same way
editing a gallery does. That is the reason this is a script rather than an exported bitmap.

PNGs are rendered by headless Edge, the same instrument the 26.8.12 layout check uses, and
each one is asserted to be exactly the size the directory expects. A renderer that silently
produced a 771-pixel banner would be invisible in review and wrong on the page.
"""
import os
import re
import subprocess
import sys
import tempfile

INK   = '#14141A'
PAPER = '#F7F3EC'
AMBER = '#E9A13B'
OCHRE = '#B87A1E'          # the amber, darkened to hold contrast on the paper background
GREY  = '#6E6E7A'

FONT    = 'Avenir Next, Helvetica Neue, sans-serif'
SERIF   = 'Didot, Georgia, serif'
TAGLINE = 'Photo galleries that look right and load fast.'

EDGE = '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT  = os.path.join(ROOT, '.wordpress-org')


def justified(aspects, width, gap):
	"""Tile widths for one row: a common height such that the row fills `width` exactly."""
	height = (width - gap * (len(aspects) - 1)) / sum(aspects)
	return [(a * height, height) for a in aspects]


# ------------------------------------------------------------------ the icon
# Two rows, five tiles. Few enough shapes to survive 24px, which is where the directory
# draws it in a search result -- the variant with eight tiles turned to mush there.
ICON_ROWS   = [[1.35, 0.72, 1.00], [1.55, 1.00]]
ICON_ACCENT = 1                     # the narrow tile in row one, drawn in amber


def icon_svg():
	pad, gap, size = 34, 11, 256
	inner = size - 2 * pad
	rows  = [justified(a, inner, gap) for a in ICON_ROWS]
	total = sum(r[0][1] for r in rows) + gap * (len(rows) - 1)
	y     = pad + (inner - total) / 2

	tiles, index = [], 0
	for row in rows:
		x = pad
		for w, h in row:
			fill = AMBER if index == ICON_ACCENT else PAPER
			tiles.append(f'<rect x="{x:.2f}" y="{y:.2f}" width="{w:.2f}" height="{h:.2f}" rx="7" fill="{fill}"/>')
			x += w + gap
			index += 1
		y += row[0][1] + gap

	body = '\n  '.join(tiles)
	return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{size}" height="{size}" viewBox="0 0 {size} {size}">\n'
	        f'  <rect width="{size}" height="{size}" rx="48" fill="{INK}"/>\n'
	        f'  {body}\n'
	        f'</svg>\n')


# ---------------------------------------------------------------- the banner
# Here the height is fixed and the widths follow, so the run continues past the right edge
# and the motif bleeds. Solving for width instead would have forced every row to end level,
# which is the one thing a justified grid's last row never does.
BANNER_ROWS = [
	[1.50, 0.75, 1.28, 1.60, 0.95],
	[0.80, 1.45, 1.10, 1.35, 1.05],
	[1.30, 1.00, 0.72, 1.55, 1.20],
]
BANNER_OPACITY = [
	[0.92, 0.50, 0.74, 0.32, 0.58],
	[0.58, 0.86, 0.38, 0.66, 0.30],
	[0.40, 0.68, 1.00, 0.34, 0.52],
]
BANNER_ACCENT = (2, 2)

W, H = 772, 250


def banner_svg():
	height, gap, x0 = 58, 10, 432
	y0 = (H - (3 * height + 2 * gap)) / 2
	edge = W + 60

	tiles = []
	for r, aspects in enumerate(BANNER_ROWS):
		x, y = x0, y0 + r * (height + gap)
		for c, aspect in enumerate(aspects):
			if x > edge:
				break
			fill = AMBER if (r, c) == BANNER_ACCENT else INK
			tiles.append(f'<rect x="{x:.1f}" y="{y:.1f}" width="{aspect * height:.1f}" height="{height}" '
			             f'rx="5" fill="{fill}" opacity="{BANNER_OPACITY[r][c]:.2f}"/>')
			x += aspect * height + gap

	body = '\n  '.join(tiles)
	return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">\n'
	        f'  <rect width="{W}" height="{H}" fill="{PAPER}"/>\n'
	        f'  {body}\n'
	        f'  <text x="56" y="128" font-family="{SERIF}" font-size="62" fill="{INK}">Lichtbild</text>\n'
	        f'  <text x="59" y="166" font-family="{FONT}" font-size="20" font-weight="600" letter-spacing="7.5" fill="{OCHRE}">GALLERY</text>\n'
	        f'  <text x="59" y="200" font-family="{FONT}" font-size="15" fill="{GREY}">{TAGLINE}</text>\n'
	        f'</svg>\n')


# ----------------------------------------------------------------- rendering
def render(svg_path, width, height, out, scale=1):
	"""Screenshots the SVG at exactly width*scale by height*scale, or fails."""
	with tempfile.TemporaryDirectory() as tmp:
		page = os.path.join(tmp, 'page.html')
		with open(page, 'w', encoding='utf-8') as fh:
			fh.write('<!doctype html><meta charset="utf-8">'
			         '<style>html,body{margin:0;padding:0}svg{display:block}</style>\n')
			fh.write(open(svg_path, encoding='utf-8').read())
		subprocess.run([EDGE, '--headless', '--disable-gpu', '--hide-scrollbars',
		                f'--force-device-scale-factor={scale}',
		                f'--window-size={width},{height}',
		                f'--screenshot={out}', 'file://' + page],
		               check=True, capture_output=True)
	return png_size(out)


def png_size(path):
	"""Width and height straight out of the IHDR chunk -- no image library needed."""
	with open(path, 'rb') as fh:
		head = fh.read(24)
	if head[:8] != b'\x89PNG\r\n\x1a\n':
		raise SystemExit(f'not a PNG: {path}')
	return int.from_bytes(head[16:20], 'big'), int.from_bytes(head[20:24], 'big')


def main():
	if not os.path.exists(EDGE):
		raise SystemExit(f'headless renderer not found at {EDGE}')
	os.makedirs(OUT, exist_ok=True)

	icon_path   = os.path.join(OUT, 'icon.svg')
	banner_path = os.path.join(OUT, 'banner.svg')
	open(icon_path, 'w', encoding='utf-8').write(icon_svg())
	open(banner_path, 'w', encoding='utf-8').write(banner_svg())

	wanted = [
		(icon_path,   256, 256, 'icon-256x256.png',    1, (256, 256)),
		(icon_path,   128, 128, 'icon-128x128.png',    1, (128, 128)),
		(banner_path, W,   H,   'banner-772x250.png',  1, (772, 250)),
		(banner_path, W,   H,   'banner-1544x500.png', 2, (1544, 500)),
	]

	failures = 0
	for src, w, h, name, scale, expected in wanted:
		out = os.path.join(OUT, name)
		got = render(src, w, h, out, scale)
		if got == expected:
			print(f'  [OK]   {name}: {got[0]}x{got[1]}, {os.path.getsize(out) // 1024} KB')
		else:
			print(f'  [FAIL] {name}: rendered {got[0]}x{got[1]}, expected {expected[0]}x{expected[1]}')
			failures += 1

	# The name on the banner has to be the name in the plugin header. A wordmark is the one
	# string no test would otherwise read, and this plugin has been renamed twice.
	header = open(os.path.join(ROOT, 'lichtbild-gallery.php'), encoding='utf-8').read(2048)
	name = re.search(r'^\s*\*\s*Plugin Name:\s*(.+?)\s*$', header, re.M)
	svg = open(banner_path, encoding='utf-8').read()
	if not name:
		print('  [FAIL] could not read Plugin Name from lichtbild-gallery.php')
		failures += 1
	else:
		words = name.group(1).split()
		missing = [w for w in words if w.lower() not in svg.lower()]
		if missing:
			print(f'  [FAIL] banner does not carry the plugin name: missing {missing}')
			failures += 1
		else:
			print(f'  [OK]   banner carries the plugin name: {name.group(1)}')

	print()
	print('brand artwork built' if not failures else f'{failures} failure(s)')
	return 1 if failures else 0


if __name__ == '__main__':
	sys.exit(main())
