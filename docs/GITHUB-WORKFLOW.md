# GitHub workflow for ongoing FreightKit updates

## First upload

1. Install GitHub Desktop and sign in.
2. Create a local repository named `freightkit` using this extracted project folder as its repository directory. If creating from the parent directory, GitHub Desktop will create a `freightkit` subfolder; make sure the site's `index.html` is at the repository root.
3. Commit all website files and publish the repository to your GitHub account. A private repo is fine; the public website can still be served from separate hosting.
4. Check the repo on GitHub and ensure `index.html`, `assets/`, `tools/`, `downloads/`, `contact-handler.php`, `.htaccess`, and all page folders are present.

## Suggested branches

- `main`: latest stable code.
- `feature/<short-name>`: optional branch for a large feature or redesign.
- For a small typo or content fix, commit directly to `main` only after testing.

## Every update

1. Pull the latest changes first if editing from more than one computer.
2. Edit the relevant file.
3. Test locally. For layout/calculator testing use `python -m http.server 8000`; for PHP handler tests use PHP or the actual host.
4. Review GitHub Desktop's changed-file list and diff. Do not include secrets, customer files, generated archives, or `contact-config.php`.
5. Commit with a specific message such as `Fix container load calculator validation`.
6. Push to GitHub.
7. Back up the current live site or use your host's file history, then upload changed files via SFTP/File Manager. Keep the site's folder structure and preserve server-only config files.
8. Run a smoke test on the live domain. If broken, restore the prior version or revert the commit and redeploy.

## Recommended commit messages

- `Fix: ...` for a bug fix
- `Add: ...` for a new tool/page
- `Update: ...` for content or styling
- `Docs: ...` for instructions only

## Later automation

After the manual deployment is stable, you can automate deployment using your hosting provider's Git deployment feature or GitHub Actions over SFTP. Store credentials only in GitHub Actions Secrets, never in source files. Use a staging subdomain and test before production if your host supports it.
