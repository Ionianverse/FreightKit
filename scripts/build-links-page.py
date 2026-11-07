"""Build links/README.md (and links/links.csv): every public page and download of the site, with its
live link on freightkit.in and the source file in this repository.

Run after adding or renaming a page:   python scripts/build-links-page.py
"""
import csv
import html
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "links"
LIVE = "https://freightkit.in/"
REPO = "https://github.com/Ionianverse/FreightKit/blob/main/"
SKIP = {"bo-src", "sample", "assets", "scripts", "docs", "links", "node_modules", ".git"}

# Section for each top folder, in the order shown.
SECTIONS = [
    ("Main pages", lambda p: p.count("/") <= 1 and p.split("/")[0] not in {"tools", "guides", "bo-planning", "embed", "contact-success", "404.html"}),
    ("Calculators and tools", lambda p: p.startswith("tools/")),
    ("Guides", lambda p: p.startswith("guides/")),
    ("BO Planning (sign-in required)", lambda p: p.startswith("bo-planning/")),
    ("Other pages", lambda p: True),
]


def meta(text, pattern):
    m = re.search(pattern, text, re.I | re.S)
    return html.unescape(re.sub(r"\s+", " ", m.group(1)).strip()) if m else ""


def pages():
    out = []
    for f in sorted(ROOT.rglob("*.html")):
        rel = f.relative_to(ROOT).as_posix()
        if rel.split("/")[0] in SKIP:
            continue
        text = f.read_text(encoding="utf-8", errors="ignore")
        title = meta(text, r"<title>(.*?)</title>")
        title = "Home" if rel == "index.html" else (re.sub(r"\s*[|–-]\s*FreightKit.*$", "", title) or rel)
        desc = meta(text, r'<meta\s+name="description"\s+content="([^"]*)"')
        url_path = "" if rel == "index.html" else rel[: -len("index.html")] if rel.endswith("/index.html") else rel
        noindex = bool(re.search(r'<meta\s+name="robots"\s+content="[^"]*noindex', text, re.I))
        out.append({"path": rel, "url_path": url_path, "title": title, "desc": desc, "noindex": noindex})
    return out


def downloads():
    folder = ROOT / "downloads"
    return sorted(p.name for p in folder.iterdir() if p.is_file()) if folder.exists() else []


def cell(text, limit=140):
    text = text.replace("|", "/")
    return text if len(text) <= limit else text[: limit - 1].rstrip() + "…"


def main():
    items = pages()
    files = downloads()
    OUT.mkdir(exist_ok=True)
    lines = [
        "# FreightKit – all pages and links",
        "",
        f"Every public page of the site, with its live link. **{len(items)} pages** and **{len(files)} downloads**.",
        "",
        f"- Live site: **[freightkit.in]({LIVE})**",
        "- Spreadsheet version of this list: [links.csv](links.csv)",
        "",
        "This page is made by `python scripts/build-links-page.py`; run it again after adding or renaming a page.",
        "",
    ]
    done = set()
    rows = []
    for name, test in SECTIONS:
        group = [p for p in items if p["path"] not in done and test(p["path"])]
        # The home page and each section's own index page come first.
        group.sort(key=lambda p: (p["path"] not in ("index.html", "tools/index.html", "guides/index.html"), p["title"].lower()))
        if not group:
            continue
        lines += [f"## {name} ({len(group)})", "", "| Page | What it does | Live | Source |", "|---|---|---|---|"]
        for p in group:
            done.add(p["path"])
            live = LIVE + p["url_path"]
            note = " *(not in search engines)*" if p["noindex"] else ""
            lines.append(f"| **{cell(p['title'], 60)}**{note} | {cell(p['desc'])} | [open]({live}) | [{p['path']}]({REPO}{p['path']}) |")
            rows.append([name, p["title"], p["desc"], live, p["path"]])
        lines.append("")
    if files:
        lines += [f"## Downloads – templates and workbooks ({len(files)})", "", "| File | Type | Live |", "|---|---|---|"]
        for f in files:
            kind = "Excel workbook" if f.endswith(".xlsx") else "CSV template" if f.endswith(".csv") else f.rsplit(".", 1)[-1]
            lines.append(f"| {f} | {kind} | [download]({LIVE}downloads/{f}) |")
            rows.append(["Downloads", f, kind, f"{LIVE}downloads/{f}", f"downloads/{f}"])
        lines.append("")
    (OUT / "README.md").write_text("\n".join(lines), encoding="utf-8")
    with open(OUT / "links.csv", "w", newline="", encoding="utf-8-sig") as fh:
        w = csv.writer(fh)
        w.writerow(["Section", "Page", "What it does", "Live link", "Source file"])
        w.writerows(rows)
    print(f"links/README.md and links/links.csv: {len(items)} pages, {len(files)} downloads")


if __name__ == "__main__":
    main()
