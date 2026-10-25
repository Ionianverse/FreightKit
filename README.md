# FreightKit — logistics and supply-chain tools

FreightKit is a static-first website containing browser-based calculators, CSV review workflows, downloadable CSV and Excel templates, guides and service pages. The current canonical domain in the source is `https://freightkit.in`. The site is designed to be version-controlled in GitHub and hosted on ordinary PHP-enabled shared hosting (for example, cPanel hosting). It does **not** require Netlify, a Node.js build, or a database for the current browser tools.

## Repository structure

```text
freightkit/
├── index.html                 # Homepage (site document root)
├── assets/                    # Shared CSS, JavaScript and images
├── tools/                     # Individual calculators/data tools
├── templates/                 # Template landing page
├── downloads/                 # Actual downloadable CSV files
│   └── *.xlsx                 # Formula-led Excel workbooks
├── guides/                    # Logistics guides
├── solutions/                 # Service/solution pages
├── contact/                   # Contact page
├── suggest/                   # Suggestion form
├── contact-handler.php        # PHP form processor for PHP hosting
├── contact-config.example.php # Safe example; copy/edit on the server
├── .htaccess                  # Apache/cPanel hosting defaults
├── sitemap.xml                # Update to your final domain
├── robots.txt                 # Update to your final domain
├── docs/                      # Setup, release and maintenance guides
└── scripts/                   # Local checks and domain configuration helper
```

## Run and test locally

All internal links, CSS and JavaScript use **relative paths** (for example `../../assets/css/style.css`), so the site works at a domain root *and* inside a subfolder such as `https://username.github.io/freightkit/`. Serve it with a local web server rather than double-clicking HTML files. Run `python scripts/check-site.py` before every upload: it fails if any page has a broken link or a root-relative path (`/assets/...`) that would break CSS in a subfolder. The only exception is `404.html`, which keeps root paths because it can be served at any URL depth; `set-domain.ps1` turns them into absolute URLs.

**Static preview (Python installed):**

```bash
python -m http.server 8000
```

Open `http://localhost:8000/`. This preview will show pages/calculators/downloads, but **PHP contact form submission will not run** in Python's static server. Test forms on your PHP hosting or a local PHP server.

**PHP local preview (PHP installed):**

```bash
php -S localhost:8000
```

The form will still require a valid `contact-config.php` and a configured mail transport to actually deliver email.

## Launch steps

1. Buy/register your domain and a PHP-enabled shared hosting plan. Point the domain to that hosting account and enable the free SSL certificate.
2. This source currently uses `https://freightkit.in`. If deploying to a different final domain, replace the current domain and update canonical URLs before publishing.
3. In PowerShell, from the project folder, run this only when changing domains:

   ```powershell
   .\scripts\set-domain.ps1 -Domain "https://your-real-domain.com"
   ```

   Or use the manual replacement instructions in `docs/DOMAIN-AND-SEO.md`. This updates canonical URLs, structured website URLs, sitemap and robots.txt.
4. Upload the **contents of this folder** (not the ZIP itself and not the parent folder) to the hosting account's web root, commonly `public_html/`. Keep all directories together. `index.html` must be directly inside the web root.
5. Copy `contact-config.example.php` to `contact-config.php` on the server and set `to_email` and `from_email`. The real config is excluded from Git by `.gitignore`.
6. Configure email delivery with your host. PHP `mail()` may not be enabled or may have poor deliverability; use your hosting provider's authenticated SMTP setup or a reputable transactional email service if required. Test the form end-to-end before announcing it.
7. Confirm HTTPS works, then enable the HTTPS redirect section in `.htaccess` if your host supports Apache rewrite rules.
8. Test every important page, tool, CSV download, contact form, mobile layout and 404 page. Submit your sitemap in Google Search Console after the site is live.

## Hosting on GitHub Pages (static only)

The site can also be published free on GitHub Pages: in the repository go to **Settings → Pages → Deploy from a branch → `main` / root**. The included `.nojekyll` file makes GitHub serve every file as-is. Then run `scripts/set-domain.ps1 -Domain "https://username.github.io/freightkit"` (or your custom domain) and push. GitHub Pages cannot run PHP, so the contact and suggestion forms will not send email there; use PHP hosting or switch the forms to a form service.

## SEO included

Every indexable page has a unique title and description, a canonical URL, Open Graph/Twitter tags with a 1200×630 share image (`assets/img/og-image.png`), a favicon and web manifest, and JSON-LD structured data (Organization and WebSite on the homepage, BreadcrumbList on every page, WebApplication on calculators, Article on guides). Tool and guide pages show visible breadcrumbs, and calculators link to related calculators. `sitemap.xml` lists only indexable pages with `lastmod` dates. Utility pages (`404`, `contact-success`, `embed`, the legacy builder alias) are `noindex`. When you add a page, copy the `<head>` of a similar page and add it to `sitemap.xml`.

## GitHub and continuous updates

GitHub stores the source code and history; it is **not** your website hosting provider in this setup. Your domain and shared hosting serve the public website.

Recommended workflow:

1. Keep this whole project in one repository named `freightkit`.
2. Keep `main` as the stable branch. Create a short branch for larger changes, e.g. `feature/freight-audit`.
3. Make a backup or Git commit before changes. Edit files in VS Code or another editor.
4. Test locally, inspect the changed files, commit with a clear message and push to GitHub.
5. For shared hosting, publish the tested update by uploading only changed files using the host File Manager or SFTP. Do not overwrite `contact-config.php` or other server-only configuration. For a more automated setup, configure Git deployment or a carefully secured CI/CD deployment after the first manual launch.
6. Keep a release note for changes that affect calculations or public-facing promises. Test the affected calculator and at least one unrelated page after each deployment.

Never commit passwords, hosting credentials, API keys, customer documents, `contact-config.php`, or private production data.

## Contact forms

The contact and suggestion forms post to `contact-handler.php`. The handler validates input, uses a honeypot field, and emails the configured inbox. It depends on PHP and a working mail transport on the host. This is not an inbox/database; add a database or ticketing system only if you need stored enquiries, team access, spam controls, or a customer portal. Email delivery must be tested and can require SMTP configuration.

The browser-based freight invoice audit and PO/shipment exception report process CSV files on the visitor's device and export review files locally. They do not send data to FreightKit, contact suppliers, connect to an ERP, or provide live carrier/shipment updates. Five formula-led Excel workbooks support freight quote comparison, invoice audits, PO/ETA exceptions, supplier scorecards, and inventory reorder planning from the Templates page.

## Current limitations

- This is a static-first site; browser calculators run on the visitor's device.
- No user accounts, cloud-saved workspaces, backend database, subscription billing, payment checkout, or ERP integrations are included.
- The CSV tools process files in the browser unless a future backend is explicitly added.
- Calculations are estimates and must be checked against current operational, carrier, customs and tax requirements.
- The website is not fully monetized by merely uploading it. Payments, paid product delivery, analytics, consent controls and any advertising integration need separate setup and testing.

See `docs/` for domain, hosting, GitHub and release instructions.
