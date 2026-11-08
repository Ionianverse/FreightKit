#!/usr/bin/env python3
"""Build the encrypted BO Planning System bundle.

The readable tool lives in the private, git-ignored folder ``bo-src/``:

    bo-src/app.html        page markup shown after sign-in
    bo-src/bo.css          styles
    bo-src/rules.json      built-in rules (same format as "Export rules file" on the Rules page)
    bo-src/master.json     built-in item master, pockets and BOM (made by scripts/build-bo-master.py; optional)
    bo-src/js/*.js         rules-default.js (from rules.json), master-default.js (from master.json), engine.js, planning.js, app.js
    bo-src/.credentials.json   {"id": "...", "password": "..."}  (never commit)

This script packs those files, compresses them, and encrypts them with AES-256-GCM under a new random key
on every build. The key goes to ``bo-api/.appkey.php`` (git-ignored, never served by the web server); the
server hands it only to signed-in, approved users, so ``bo-planning/app.bundle`` is useless without an account.

Run from anywhere:  python scripts/build-bo-bundle.py
New built-in rules:  python scripts/build-bo-bundle.py --rules "path/to/bo-rules-2026-11-03.json"
                     (the file from "Export rules file"; it is copied to bo-src/rules.json)
Accounts, approval and saved work: bo-api/ (PHP + MySQL); see bo-src/HOW-TO-UPDATE.md.
"""
import argparse
import base64
import json
import os
import secrets
import sys
import zlib
from datetime import datetime, timezone
from hashlib import pbkdf2_hmac
from pathlib import Path

try:
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM
except ImportError:
    sys.exit("Install the 'cryptography' package first:  python -m pip install --user cryptography")

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "bo-src"
OUT = ROOT / "bo-planning" / "app.bundle"
KEY_PHP = ROOT / "bo-api" / ".appkey.php"
SCRIPTS = ["rules-default.js", "master-default.js", "usmap.js", "engine.js", "planning.js", "app.js"]
ITERATIONS = 600_000


def credentials():
    user = os.environ.get("BO_ID")
    password = os.environ.get("BO_PASSWORD")
    if not (user and password):
        cred_file = SRC / ".credentials.json"
        if not cred_file.is_file():
            sys.exit(f"Missing {cred_file}. Create it with {{\"id\": \"...\", \"password\": \"...\"}}.")
        data = json.loads(cred_file.read_text(encoding="utf-8"))
        user, password = data.get("id", ""), data.get("password", "")
    user = user.strip().lower()
    if len(user) < 3 or len(password) < 12:
        sys.exit("Use an ID of at least 3 characters and a password of at least 12 characters.")
    return user, password


RULES = SRC / "rules.json"
RULES_JS = SRC / "js" / "rules-default.js"
RULES_JS_HEAD = """/* Built-in rules for the BO Planning System (company rules). Private: never commit this file.
   Made from bo-src/rules.json by scripts/build-bo-bundle.py. Change rules.json (or export from the
   Rules page and run the script with --rules), not this file. */
(function (root) {
  'use strict';
  root.BO_DEFAULT_RULES = """
RULES_JS_TAIL = ";\n})(typeof window !== 'undefined' ? window : globalThis);\n"


def load_rules(path):
    try:
        data = json.loads(Path(path).read_text(encoding="utf-8-sig"))
    except (OSError, ValueError) as err:
        sys.exit(f"Could not read the rules file {path}: {err}")
    if data.get("schema") != "freightkit-bo-rules" or not data.get("routes") or not data.get("chains"):
        sys.exit(f"{path} is not a BO rules file (use “Export rules file” on the Rules page).")
    return data


def sync_rules(new_file):
    """Copy an exported rules file in (optional), then write rules-default.js from bo-src/rules.json."""
    if new_file:
        data = load_rules(new_file)
        if RULES.is_file():
            old = load_rules(RULES)
            backup = SRC / f"rules-before-{datetime.now().strftime('%Y%m%d-%H%M%S')}.json"
            backup.write_text(json.dumps(old, indent=2, ensure_ascii=False), encoding="utf-8")
            print(f"Previous rules kept as {backup.relative_to(ROOT)}")
        RULES.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
        print(f"New built-in rules: {RULES.relative_to(ROOT)} (from {new_file})")
    if not RULES.is_file():
        return  # keep the existing rules-default.js
    data = load_rules(RULES)
    RULES_JS.write_text(RULES_JS_HEAD + json.dumps(data, indent=2, ensure_ascii=False) + RULES_JS_TAIL, encoding="utf-8")
    steps = sum(len(r.get("steps", [])) for r in data["routes"].values())
    print(f"Rules version {data.get('version')} (updated {data.get('updated', '?')}): {len(data['routes'])} routes, {steps} steps.")


MASTER = SRC / "master.json"
MASTER_JS = SRC / "js" / "master-default.js"


def sync_master():
    """Write master-default.js from bo-src/master.json (an empty master when there is none)."""
    data = {}
    if MASTER.is_file():
        data = json.loads(MASTER.read_text(encoding="utf-8"))
        print(f"Master data from {data.get('source', '?')} ({data.get('updated', '?')}): {len(data.get('items', {})):,} items, "
              f"{len(data.get('pockets', {})):,} pockets, {len(data.get('bom', [])):,} BOM rows.")
    MASTER_JS.write_text("/* Built-in item master, pockets and BOM for the BO Planning System. Private: never commit.\n"
                         "   Made from bo-src/master.json by scripts/build-bo-bundle.py. */\n"
                         "(function (root) { 'use strict'; root.BO_DEFAULT_MASTER = "
                         + json.dumps(data, ensure_ascii=False, separators=(",", ":"))
                         + "; })(typeof window !== 'undefined' ? window : globalThis);\n", encoding="utf-8")


def main():
    parser = argparse.ArgumentParser(description="Build the encrypted BO Planning System bundle.")
    parser.add_argument("--rules", help="rules file exported from the Rules page, to become the built-in rules")
    args = parser.parse_args()
    sync_rules(args.rules)
    sync_master()
    for name in ["app.html", "bo.css", *[f"js/{s}" for s in SCRIPTS]]:
        if not (SRC / name).is_file():
            sys.exit(f"Missing private source file: bo-src/{name}")
    payload = {
        "v": 1,
        "built": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "html": (SRC / "app.html").read_text(encoding="utf-8"),
        "css": (SRC / "bo.css").read_text(encoding="utf-8"),
        "scripts": [{"name": s, "code": (SRC / "js" / s).read_text(encoding="utf-8")} for s in SCRIPTS],
    }
    raw = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    deflate = zlib.compressobj(9, zlib.DEFLATED, -15)  # raw deflate, read in the browser with DecompressionStream
    packed = deflate.compress(raw) + deflate.flush()
    # A new random key on every build; only the server hands it out, to signed-in approved users.
    key, iv = secrets.token_bytes(32), secrets.token_bytes(12)
    sealed = AESGCM(key).encrypt(iv, packed, b"bo-bundle-2")
    bundle = {"format": "bo-bundle-2", "iv": base64.b64encode(iv).decode(), "data": base64.b64encode(sealed).decode()}
    OUT.write_text(json.dumps(bundle), encoding="utf-8")
    KEY_PHP.write_text("<?php\n// Key of bo-planning/app.bundle, built " + payload["built"] + ". Private: never commit; the web server never serves it.\n"
                       "return '" + base64.b64encode(key).decode() + "';\n", encoding="utf-8")
    print(f"Built {OUT.relative_to(ROOT)}: {len(raw):,} bytes of source, {len(sealed):,} bytes encrypted.")


if __name__ == "__main__":
    main()
