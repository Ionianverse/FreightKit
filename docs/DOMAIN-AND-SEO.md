# Domain, hosting and SEO setup

## What you need to purchase

1. **Domain name**: register a domain through a registrar you trust. Check availability, renewal price, privacy options and DNS controls before paying.
2. **Web hosting**: choose a plan that supports static HTML/CSS/JavaScript, PHP 8+, SSL, File Manager or SFTP, and outbound email/SMTP. A basic shared hosting plan is enough for the current version; you do not need a VPS or database yet.
3. **Business email (recommended)**: use an address on your domain, such as `hello@yourdomain.com`. Set up the host's SPF/DKIM/DMARC guidance to improve delivery.

Domain registration and hosting are separate services even if bought from one company. GitHub stores the code; the hosting account serves the live website.

## Connect your domain

The exact DNS values depend on the host. Follow its onboarding instructions rather than copying generic IP addresses from an unrelated tutorial. Common approaches are:

- Change nameservers to the hosting provider's nameservers; or
- Keep DNS at the registrar and add the A/AAAA/CNAME records specified by the host.

Add both the root domain and `www` version in the hosting panel if you want both to work, then select one canonical version and redirect the other to it. Wait for DNS propagation and issue/enable SSL before forcing HTTPS.

## Set the domain in this project

After deciding the final public URL, run from the repository root in PowerShell:

```powershell
.\scripts\set-domain.ps1 -Domain "https://your-real-domain.com"
```

Use the domain without a trailing slash. The script updates all canonical URLs, JSON-LD website URLs, `sitemap.xml`, and `robots.txt`. Inspect `git diff` afterward and commit the change. Do not leave `YOUR-DOMAIN.com` in production.

## Search engine launch checklist

- Confirm each important page has a unique title and meta description.
- Confirm canonical tags use the final HTTPS domain.
- Confirm `https://yourdomain.com/sitemap.xml` loads and contains the final domain.
- Confirm `https://yourdomain.com/robots.txt` loads and points to that sitemap.
- Add the domain property in Google Search Console and verify ownership using the method it provides.
- Submit the sitemap and monitor indexing/coverage issues.
- Use descriptive page links, original helpful content, fast pages, mobile testing, and a clear privacy policy.
- Do not publish fake reviews, fabricated statistics, or promises of regulatory accuracy.
