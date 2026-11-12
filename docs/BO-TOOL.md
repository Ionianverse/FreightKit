# BO Planning System – accounts, privacy and updates

The BO Planning System lives at `bo-planning/` (the old `tools/bo-remarks/` address redirects there). Only approved users can use it: anyone can request an account, and the admin approves or declines each request.

## What is public and what is private

| In this repository and on the website | Only on the owner's computer or the server |
| --- | --- |
| `bo-planning/index.html`, `login.js`, `app-shell.css` – sign-in page, request form, admin panel and loader | `bo-src/` – the readable tool: page markup, styles, rules and logic (never committed) |
| `bo-planning/app.bundle` – the tool, encrypted | `bo-api/.appkey.php` – the bundle key, made by each build (never committed, never served) |
| `bo-api/*.php` – sign-in, accounts, saved work, admin | `bo-api/mail-settings.json` – email addresses (written by the admin panel) |
| `scripts/build-bo-bundle.py` – builds the bundle and its key | `bo-private/` (next to `public_html`) – database login, data key, encrypted saves |
| | `Data/` – business rules, logic, file layouts and real BO, stock and transfer files |

Keep a backup of `bo-src/` and of the server's `bo-private/` folder (above all `data.key`: without it saved work cannot be read).

## Accounts and sign-in

- A user requests access (name, work email, company, reason, password). The admin gets an email at the address in **Users & requests → Email settings** and approves or declines in the admin panel; the user gets an email either way.
- Passwords are stored as one-way hashes (12+ characters, letters and numbers). Five wrong passwords lock the account for 15 minutes; repeated failures from one network are slowed down.
- The session cookie is HttpOnly, Secure and SameSite=Strict. Users are signed out after one hour without activity or after 12 hours.
- Every call needs the tool's own header and the site's origin. Account events are logged (admin panel → Recent activity), including every email sent.

## How the tool is protected

The tool ships encrypted (AES-256-GCM) with a new random key on every build. The server hands the key only to a signed-in, approved user; the browser decrypts the tool in memory. Without an approved account the tool cannot be read or run.

## Privacy of files and saved work

Files are read and planned inside the browser tab. Nothing goes to the server until the user presses **Save work**: then that day's input files, rules and edits are saved encrypted with the server key, readable only by that user's account from the tool. The last three saves of each plan date are kept, for 120 plan dates. **Reset to last save** and **Reset to previous day** bring work back; the previous day's save also carries remarks and open requests to the next day.

## Email

Access requests, approvals and the contact form are sent through the sender mailbox (SMTP login at smtp.hostinger.com). The admin types the mailbox password once in Email settings; it is kept encrypted in `bo-private/`. Without it the server's basic mail is used, which Hostinger marks as spam.

## Changing the tool

1. Edit files in `bo-src/` (rules: edit on the Rules page, **Export rules file**, then `python scripts/build-bo-bundle.py --rules "<exported file>"`).
2. Run `python scripts/build-bo-bundle.py` (needs `python -m pip install --user cryptography` once). It writes `bo-planning/app.bundle` and `bo-api/.appkey.php`.
3. Commit `bo-planning/app.bundle` (never `.appkey.php`) and upload both files to the website together.

Each user's rules are saved with their work; **Restore defaults** on the Rules page switches back to the built-in rules.
