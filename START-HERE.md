# Start here — FreightKit self-hosted package

This folder is the complete website project. It is prepared for GitHub version control and PHP-enabled shared hosting (cPanel-style hosting), not Netlify.

## Do these in order

1. **Choose a domain and PHP hosting plan.** A basic shared plan with SSL, PHP 8+, File Manager/SFTP and email/SMTP support is enough for this version.
2. **Set your domain.** Run PowerShell from this folder:
   `./scripts/set-domain.ps1 -Domain "https://your-real-domain.com"`
   On Windows PowerShell, use the path `scripts\set-domain.ps1` if needed. Replace the example with the exact domain you register. Then run `git diff` and check the changes.
3. **Put the whole project on GitHub.** Create a repository named `freightkit` using this folder as the repository root. Commit and publish all files/folders. Do not commit passwords or the real `contact-config.php`.
4. **Upload the site to hosting.** Upload the *contents* of this folder to your web root (often `public_html`). `index.html` should be directly in that folder.
5. **Configure the forms.** On the server, copy `contact-config.example.php` to `contact-config.php`, set your inbox and domain email, and configure SMTP/mail delivery with your host. The config is intentionally ignored by Git.
6. **Test before public launch.** Open `docs/LAUNCH-CHECKLIST.md` and test downloads, calculators, contact forms, HTTPS, mobile layout and sitemap.

## Future updates

Edit locally → test → commit/push to GitHub → upload the changed files to hosting → test the live site. GitHub keeps your version history; your hosting account serves the live site. Do not overwrite server-only `contact-config.php` during updates.

Detailed instructions: `README.md`, `docs/GITHUB-WORKFLOW.md`, `docs/DOMAIN-AND-SEO.md`, and `docs/LAUNCH-CHECKLIST.md`.
