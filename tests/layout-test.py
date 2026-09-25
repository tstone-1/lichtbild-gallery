"""Real CSS check: uv run --with playwright python tests/layout-test.py.

Install Chromium with `uv run --with playwright python -m playwright install chromium`.
On Windows this uses the installed Edge channel instead. No images or network are needed.
"""
from pathlib import Path
import os
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix="lichtbild-layout-") as folder:
    page_path = Path(folder) / "gallery.html"
    subprocess.run(["php", str(ROOT / "tests/review-regressions-test.php"), "--html", str(page_path)], check=True)
    markup = page_path.read_text(encoding="utf-8")
    css = (ROOT / "assets/css/lichtbild.css").read_text(encoding="utf-8")
    page_path.write_text('<!doctype html><meta charset="utf-8"><style>' + css + '</style>' + markup, encoding="utf-8")
    with sync_playwright() as p:
        browser = p.chromium.launch(channel="msedge" if os.name == "nt" else None)
        page = browser.new_page(viewport={"width": 1000, "height": 800})
        page.route("https://**/*", lambda route: route.abort())
        page.goto(page_path.as_uri(), wait_until="domcontentloaded")
        def geometry():
            return page.locator('.lichtbild-item').first.evaluate("el => ({basis: parseFloat(getComputedStyle(el).flexBasis), height: el.getBoundingClientRect().height})")
        desktop = geometry()
        page.set_viewport_size({"width": 600, "height": 800})
        mobile = geometry()
        assert desktop["basis"] == 700, desktop
        assert mobile["basis"] == 220, mobile
        assert 0 < mobile["height"] < desktop["height"], (desktop, mobile)
        print("[OK] actual gallery CSS: desktop basis 700, mobile basis 220; mobile height decreases")
        browser.close()
