# FreightKit launch and release checklist

## Before first launch

- [ ] Replace `YOUR-DOMAIN.com` with the final domain using the supplied script.
- [ ] Point DNS to the hosting provider and confirm SSL works.
- [ ] Upload the **contents** of this project to the hosting web root (`public_html/` on many cPanel hosts).
- [ ] Ensure `index.html` is directly in the web root, not nested in another folder.
- [ ] Copy `contact-config.example.php` to `contact-config.php` on the server and configure both email addresses.
- [ ] Configure the host's mail transport or authenticated SMTP and test both contact and suggestion forms.
- [ ] Test all CSV downloads, especially files under `/downloads/`.
- [ ] Test at least one example, one invalid input, and one edge case for each important calculator.
- [ ] Check desktop, mobile, navigation, contact button, 404 page and browser console.
- [ ] Run `python scripts/check-site.py` — it must pass with no errors.
- [ ] Verify canonical URLs, sitemap and robots.txt.
- [ ] Paste a page URL into a social share preview checker (e.g. LinkedIn Post Inspector) to confirm the share image appears.
- [ ] Review Privacy and Terms for your actual services, hosting, email provider, analytics, ads and payments.
- [ ] Create a backup and tag/record the first production release.

## Every update

- [ ] Pull latest GitHub changes.
- [ ] Make a small, scoped change.
- [ ] Test locally, run `python scripts/check-site.py` and review the diff.
- [ ] Commit and push to GitHub.
- [ ] Back up live files before a large change.
- [ ] Upload only changed files; do not overwrite server-only `contact-config.php`.
- [ ] Test the affected feature and homepage on the live domain.
- [ ] Record the release/change in GitHub commit history.
