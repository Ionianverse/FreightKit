# BO Planning System – sign-in, privacy and updates

The BO Planning System lives at `bo-planning/` (the old `tools/bo-remarks/` address redirects there) and is for one authorized user (one ID and password).

## What is public and what is private

| Public (in this repository and on the website) | Private (only on the owner's computer) |
| --- | --- |
| `bo-planning/index.html` – sign-in page and app shell | `bo-src/` – the readable tool: page markup, styles, rules and logic |
| `bo-planning/login.js`, `app-shell.css` – sign-in and loader | `bo-src/.credentials.json` – the ID and password |
| `bo-planning/app.bundle` – the tool, encrypted | Real BO, stock and transfer files (`sample/`) |
| `scripts/build-bo-bundle.py` – builds the encrypted bundle | |

`bo-src/` and `sample/` are in `.gitignore` and must never be committed. Keep a backup of `bo-src/` (for example in a private cloud folder); it is the only readable copy of the tool.

## How sign-in works

The tool is encrypted with AES-256-GCM using a key derived from the ID and password (PBKDF2-SHA256, 600,000 rounds). The sign-in page downloads the encrypted bundle and decrypts it in the browser. A wrong ID or password cannot decrypt it, so the tool cannot be seen or used without them. Nothing is sent to a server, and the decrypted tool stays in memory only; signing out or refreshing clears it.

## Privacy of files

BO, stock, transfer and contact files are read inside the browser tab. They are never uploaded or stored, and no other visitor can see them. A Content Security Policy stops the page from sending data to other websites. Only rules saved on the Rules page are kept, in that browser; “Forget everything on this device” removes them.

## Changing the tool or the password

1. To change the built-in rules, edit them on the Rules page, press **Export rules file**, then run `python scripts/build-bo-bundle.py --rules "<exported file>"`. The file becomes `bo-src/rules.json` (the previous one is kept as a dated backup in `bo-src/`).
2. To change the tool itself, edit files in `bo-src/`. To change the password, edit `bo-src/.credentials.json`.
3. Run `python scripts/build-bo-bundle.py` (needs `python -m pip install --user cryptography` once).
4. Commit the new `bo-planning/app.bundle` and upload it to the website.

Rules saved on the Rules page live only in that browser and take priority over the built-in rules there. The Rules page says which rules are in use; **Restore defaults** switches back to the built-in rules.

## Publishing to the website

Upload the `bo-planning/` folder (index.html, login.js, app-shell.css, app.bundle) and the updated pages to the host. Every page's menu has a **BO Planning** tab that opens the sign-in page; the page is `noindex`, so search engines do not list it.
