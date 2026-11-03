#!/usr/bin/env python3
"""Build the encrypted BO Planning System bundle.

The readable tool lives in the private, git-ignored folder ``bo-src/``:

    bo-src/app.html        page markup shown after sign-in
    bo-src/bo.css          styles
    bo-src/js/*.js         rules-default.js, engine.js, planning.js, app.js
    bo-src/.credentials.json   {"id": "...", "password": "..."}  (never commit)

This script packs those files, compresses them, and encrypts them with AES-256-GCM.
The key comes from the ID and password (PBKDF2-SHA256), so the published file
``bo-planning/app.bundle`` cannot be read or used without them.

Run from anywhere:  python scripts/build-bo-bundle.py
Change the password: edit bo-src/.credentials.json (or set BO_ID / BO_PASSWORD), then run again.
"""
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
SCRIPTS = ["rules-default.js", "engine.js", "planning.js", "app.js"]
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


def main():
    for name in ["app.html", "bo.css", *[f"js/{s}" for s in SCRIPTS]]:
        if not (SRC / name).is_file():
            sys.exit(f"Missing private source file: bo-src/{name}")
    user, password = credentials()
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
    salt, iv = secrets.token_bytes(16), secrets.token_bytes(12)
    key = pbkdf2_hmac("sha256", f"{user}\n{password}".encode("utf-8"), salt, ITERATIONS, dklen=32)
    sealed = AESGCM(key).encrypt(iv, packed, b"bo-bundle-1")
    bundle = {
        "format": "bo-bundle-1",
        "kdf": "PBKDF2-SHA256",
        "iterations": ITERATIONS,
        "salt": base64.b64encode(salt).decode(),
        "iv": base64.b64encode(iv).decode(),
        "data": base64.b64encode(sealed).decode(),
    }
    OUT.write_text(json.dumps(bundle), encoding="utf-8")
    print(f"Built {OUT.relative_to(ROOT)}: {len(raw):,} bytes of source, {len(sealed):,} bytes encrypted.")


if __name__ == "__main__":
    main()
