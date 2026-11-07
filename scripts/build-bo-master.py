#!/usr/bin/env python3
"""Save the item master, pockets and material list (BOM) from a BO workbook as the BO Planning
System's built-in master data (bo-src/master.json, private and never committed).

The tool uses this master when the plan is built from the ERP Daily Back Order Report and no
previous BO workbook is added. Run it again whenever items, pockets or BOMs change:

    python scripts/build-bo-master.py "path/to/BO report.xlsx"
    python scripts/build-bo-bundle.py

Sheets read (found by their headers, not their names):
    item master   Item, Form, Config (or Cat), Size, Paint, Unit Weight, Product Code, Family Code
    pockets       first column item, a "Pockets" column
    BOM           Item, Material, Per Quantity
"""
import json
import re
import sys
from datetime import date
from pathlib import Path

try:
    import openpyxl
except ImportError:
    sys.exit("Install openpyxl first:  python -m pip install --user openpyxl")

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "bo-src" / "master.json"
norm = lambda v: re.sub(r"[^a-z0-9]", "", str(v or "").lower())
clean = lambda v: "" if v is None else (str(int(v)) if isinstance(v, float) and v.is_integer() else str(v).strip())


def number(v):
    try:
        return float(v) if v not in (None, "") else 0
    except (TypeError, ValueError):
        return 0


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    src = Path(sys.argv[1])
    wb = openpyxl.load_workbook(src, read_only=True, data_only=True)
    items, pockets, bom = {}, {}, []
    for ws in wb.worksheets:
        rows = [list(r) for r in ws.iter_rows(values_only=True)]
        if not rows:
            continue
        h = [norm(x) for x in rows[0]]
        col = lambda *names: next((h.index(n) for n in names if n in h), -1)
        ci, cf, cc, cs, cp, cw = col("item"), col("form"), col("config", "cat"), col("size"), col("paint"), col("unitweight")
        if not items and ci >= 0 and cf >= 0 and cc >= 0 and col("familycode", "baseasbly") >= 0:
            cpc, cfam = col("productcode"), col("familycode")
            for r in rows[1:]:
                it = clean(r[ci]).upper()
                if not it or it in items:
                    continue
                get = lambda c: clean(r[c]) if c >= 0 and c < len(r) else ""
                items[it] = [get(cpc), get(cfam), number(r[cw]) if cw >= 0 else 0, get(cf), get(cc), get(cs), get(cp)]
            print(f"Item master: sheet '{ws.title}', {len(items)} items")
            continue
        cpk = col("pockets")
        if not pockets and cpk > 0 and len(h) <= 4:
            for r in rows[1:]:
                it = clean(r[0]).upper()
                if it and it not in pockets:
                    pockets[it] = number(r[cpk])
            print(f"Pockets: sheet '{ws.title}', {len(pockets)} items")
            continue
        cm, cq = col("material"), col("perquantity")
        if not bom and ci >= 0 and cm >= 0 and cq >= 0:
            seen = set()
            for r in rows[1:]:
                p, m, q = clean(r[ci]).upper(), clean(r[cm]).upper(), number(r[cq])
                if p and m and q > 0 and (p, m) not in seen:
                    seen.add((p, m))
                    bom.append([p, m, q])
            print(f"Material list: sheet '{ws.title}', {len(bom)} rows")
    if not items:
        sys.exit("No item master sheet found (needs Item, Form, Config/Cat, Family Code headers).")
    data = {
        "schema": "freightkit-bo-master", "version": 1, "updated": date.today().isoformat(), "source": src.name,
        "fields": ["productCode", "familyCode", "unitWeight", "form", "config", "size", "paint"],
        "items": items, "pockets": pockets, "bom": bom,
    }
    OUT.write_text(json.dumps(data, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"Saved {OUT.relative_to(ROOT)}. Run scripts/build-bo-bundle.py to include it in the tool.")


if __name__ == "__main__":
    main()
