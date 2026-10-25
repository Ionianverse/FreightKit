# Start here — FreightKit self-hosted package

This folder is the complete website project. It is prepared for GitHub version control and PHP-enabled shared hosting (cPanel-style hosting), not Netlify.

## Do these in order

1. **Choose a domain and PHP hosting plan.** A basic shared plan with SSL, PHP 8+, File Manager/SFTP and email/SMTP support is enough for this version.
2. **Confirm your domain.** This source currently uses `https://freightkit.in`. If you will publish on a different domain, run PowerShell from this folder:
   `./scripts/set-domain.ps1 -Domain "https://your-real-domain.com"`
   On Windows PowerShell, use the path `scripts\set-domain.ps1` if needed. Replace the example with the exact final HTTPS domain. Then run `git diff` and check the changes. Do not run this for the current `freightkit.in` domain.
3. **Put the whole project on GitHub.** Create a repository named `freightkit` using this folder as the repository root. Commit and publish all files/folders. Do not commit passwords or the real `contact-config.php`.
4. **Upload the site to hosting.** Upload the *contents* of this folder to your web root (often `public_html`). `index.html` should be directly in that folder.
5. **Configure the forms.** On PHP hosting, copy `contact-config.example.php` to `contact-config.php`, set a real monitored inbox and domain email, and configure SMTP/mail delivery with your host. The config is intentionally ignored by Git. Forms do not work on GitHub Pages.
6. **Test before public launch.** Open `docs/LAUNCH-CHECKLIST.md` and test downloads, calculators, contact forms, HTTPS, mobile layout and sitemap.

## Future updates

Edit locally → test → commit/push to GitHub → upload the changed files to hosting → test the live site. GitHub keeps your version history; your hosting account serves the live site. Do not overwrite server-only `contact-config.php` during updates.

Detailed instructions: `README.md`, `docs/GITHUB-WORKFLOW.md`, `docs/DOMAIN-AND-SEO.md`, and `docs/LAUNCH-CHECKLIST.md`.
