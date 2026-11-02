# BO Planning System – sign-in, privacy and updates

The BO Planning System lives at `tools/bo-remarks/` and is for one authorized user (one ID and password).

## What is public and what is private

| Public (in this repository and on the website) | Private (only on the owner's computer) |
| --- | --- |
| `tools/bo-remarks/index.html` – sign-in page | `bo-src/` – the readable tool: page markup, styles, rules and logic |
| `tools/bo-remarks/login.js`, `login.css` – sign-in and loader | `bo-src/.credentials.json` – the ID and password |
| `tools/bo-remarks/app.bundle` – the tool, encrypted | Real BO, stock and transfer files (`sample/`) |
| `scripts/build-bo-bundle.py` – builds the encrypted bundle | |

`bo-src/` and `sample/` are in `.gitignore` and must never be committed. Keep a backup of `bo-src/` (for example in a private cloud folder); it is the only readable copy of the tool.

## How sign-in works

The tool is encrypted with AES-256-GCM using a key derived from the ID and password (PBKDF2-SHA256, 600,000 rounds). The sign-in page downloads the encrypted bundle and decrypts it in the browser. A wrong ID or password cannot decrypt it, so the tool cannot be seen or used without them. Nothing is sent to a server, and the decrypted tool stays in memory only; signing out or refreshing clears it.

## Privacy of files

BO, stock, transfer and contact files are read inside the browser tab. They are never uploaded or stored, and no other visitor can see them. A Content Security Policy stops the page from sending data to other websites. Only rules saved on the Rules page are kept, in that browser; “Forget everything on this device” removes them.

## Changing the tool or the password

1. Edit files in `bo-src/` (or change rules on the Rules page and export them into `bo-src/js/rules-default.js`).
2. To change the password, edit `bo-src/.credentials.json`.
3. Run `python scripts/build-bo-bundle.py` (needs `python -m pip install --user cryptography` once).
4. Commit the new `tools/bo-remarks/app.bundle` and upload it to the website.

## Publishing to the website

Upload the `tools/bo-remarks/` folder (index.html, login.js, login.css, app.bundle) to the same path on the host. The page is `noindex` and not linked from the public pages; open it directly at `/tools/bo-remarks/`.
