"""Build links/README.md (and links/links.csv): the FreightKit products and every public page of the
site, with its live link on freightkit.in and the source file in this repository.

Run after adding or renaming a page:   python scripts/build-links-page.py
"""
import csv
import html
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "links"
LIVE = "https://freightkit.in/"
SKIP = {"bo-src", "sample", "assets", "scripts", "docs", "links", "node_modules", ".git"}
# Not listed: templates (not offered for now), the old BO address (redirects to bo-planning/) and utility pages.
HIDE = ("templates/", "tools/bo-remarks/", "contact-success/", "embed/", "404.html")

# The products, first and in this order, with their own short description.
PRODUCTS = [
    ("bo-planning/index.html", "BO Planning System", "Daily back-order planning from your ERP reports: actions by owner, the daily BO file with remarks, requirements and the network map. Sign-in required."),
    ("document-process/index.html", "Freight invoice review", "Compare freight quotes with invoices (CSV, Excel or PDF) and get each difference with its source row."),
    ("custom-calculator/index.html", "Custom calculator builder", "Build, save and share your own calculator with your inputs and formula, no code."),
    ("services/index.html", "Custom planning systems", "Private planning tools built around your own exports and rules, like the BO Planning System."),
    ("tools/index.html", "Free calculators", "All free freight, inventory, procurement and warehouse calculators in one place."),
]
SECTIONS = [
    ("Company", lambda p: p.count("/") <= 1 and p.split("/")[0] not in {"tools", "guides"}),
    ("Calculators and tools", lambda p: p.startswith("tools/")),
    ("Guides", lambda p: p.startswith("guides/")),
    ("Other pages", lambda p: True),
]


def meta(text, pattern):
    m = re.search(pattern, text, re.I | re.S)
    return html.unescape(re.sub(r"\s+", " ", m.group(1)).strip()) if m else ""


def pages():
    out = []
    for f in sorted(ROOT.rglob("*.html")):
        rel = f.relative_to(ROOT).as_posix()
        if rel.split("/")[0] in SKIP or rel.startswith(HIDE):
            continue
        text = f.read_text(encoding="utf-8", errors="ignore")
        title = meta(text, r"<title>(.*?)</title>")
        title = "Home" if rel == "index.html" else (re.sub(r"\s*[|–-]\s*FreightKit.*$", "", title) or rel)
        desc = meta(text, r'<meta\s+name="description"\s+content="([^"]*)"')
        url_path = "" if rel == "index.html" else rel[: -len("index.html")] if rel.endswith("/index.html") else rel
        out.append({"path": rel, "url_path": url_path, "title": title, "desc": desc})
    return out


def cell(text, limit=140):
    text = text.replace("|", "/")
    return text if len(text) <= limit else text[: limit - 1].rstrip() + "…"


def main():
    items = pages()
    by_path = {p["path"]: p for p in items}
    OUT.mkdir(exist_ok=True)
    lines = [
        "# FreightKit – products and links",
        "",
        f"Our products and every public page of the website, with live links. Website: **[freightkit.in]({LIVE})** · spreadsheet version: [links.csv](links.csv)",
        "",
        "## Products",
        "",
        "| Product | What it does | Open |",
        "|---|---|---|",
    ]
    rows = []
    done = set()
    for path, name, desc in PRODUCTS:
        p = by_path.get(path)
        if not p:
            continue
        done.add(path)
        live = LIVE + p["url_path"]
        lines.append(f"| **{name}** | {desc} | [freightkit.in/{p['url_path']}]({live}) |")
        rows.append(["Products", name, desc, live, path])
    lines.append("")
    for name, test in SECTIONS:
        group = [p for p in items if p["path"] not in done and test(p["path"])]
        group.sort(key=lambda p: (p["path"] != "index.html", p["title"].lower()))
        if not group:
            continue
        lines += [f"## {name} ({len(group)})", "", "| Page | What it does | Open |", "|---|---|---|"]
        for p in group:
            done.add(p["path"])
            live = LIVE + p["url_path"]
            lines.append(f"| **{cell(p['title'], 60)}** | {cell(p['desc'])} | [open]({live}) |")
            rows.append([name, p["title"], p["desc"], live, p["path"]])
        lines.append("")
    lines += ["---", "", "Made by `python scripts/build-links-page.py`; run it again after adding or renaming a page.", ""]
    (OUT / "README.md").write_text("\n".join(lines), encoding="utf-8")
    with open(OUT / "links.csv", "w", newline="", encoding="utf-8-sig") as fh:
        w = csv.writer(fh)
        w.writerow(["Section", "Page", "What it does", "Live link", "Source file"])
        w.writerows(rows)
    print(f"links/README.md and links/links.csv: {len(rows)} links")


if __name__ == "__main__":
    main()
