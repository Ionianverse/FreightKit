#!/usr/bin/env python3
"""Pre-launch check for FreightKit: required files, broken local links/CSS/JS,
root-relative paths that break in subfolders, SEO tags and placeholder domains.

Run from anywhere:  python scripts/check-site.py
"""
from pathlib import Path
from urllib.parse import urlsplit, unquote
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
required = [
    'index.html', '404.html', 'assets/css/style.css', 'assets/js/app.js', 'assets/img/favicon.svg',
    'assets/img/og-image.png', 'site.webmanifest', 'tools/index.html', 'templates/index.html',
    'downloads/inventory-tracker.csv', 'downloads/shipment-tracker.csv', 'contact/index.html',
    'contact-handler.php', 'contact-config.example.php', '.htaccess', 'sitemap.xml', 'robots.txt', 'README.md',
    'tools/freight-invoice-audit/index.html', 'tools/po-exception-report/index.html', 'document-process/index.html',
    'assets/js/workflows.js', 'assets/js/document-process-v2.js', 'downloads/freight-invoice-audit.xlsx',
    'downloads/po-exception-tracker.xlsx', 'downloads/supplier-scorecard-workbook.xlsx',
    'downloads/freight-quote-comparison.xlsx', 'downloads/inventory-reorder-planner.xlsx',
]
errors, warnings = [], []
canonical_hosts = set()
for item in required:
    if not (ROOT / item).is_file():
        errors.append(f'Missing required file: {item}')

REF = re.compile(r'\b(?:href|src|action)="([^"]*)"')
pages = [p for p in ROOT.rglob('*.html') if '.git' not in p.parts and 'docs' not in p.parts]
checked = 0
for page in pages:
    rel = page.relative_to(ROOT).as_posix()
    text = page.read_text(encoding='utf-8')
    noindex = 'noindex' in text
    if '<title>' not in text:
        errors.append(f'{rel}: missing <title>')
    if not noindex:
        for tag, label in (('name="description"', 'meta description'), ('rel="canonical"', 'canonical URL'),
                           ('property="og:title"', 'Open Graph tags'), ('rel="icon"', 'favicon')):
            if tag not in text:
                errors.append(f'{rel}: missing {label}')
        canonical = re.search(r'<link\s+rel="canonical"\s+href="([^"]+)"', text, re.I)
        if canonical:
            host = urlsplit(canonical.group(1)).netloc.lower()
            if host:
                canonical_hosts.add(host)
    if text.count('<h1') != 1 and '<h1' in text:
        warnings.append(f'{rel}: has {text.count("<h1")} <h1> headings')
    for ref in REF.findall(text):
        if not ref or ref.startswith(('#', 'mailto:', 'tel:', 'javascript:', 'data:')):
            continue
        if "'" in ref or '+' in ref:  # built inside inline JavaScript, not a static link
            continue
        parts = urlsplit(ref)
        if parts.scheme or ref.startswith('//'):
            continue
        if ref.startswith('/') and rel != '404.html':
            errors.append(f'{rel}: root-relative path "{ref}" breaks when the site is in a subfolder; use a relative path')
            continue
        base = ROOT if ref.startswith('/') else page.parent
        target = (base / unquote(parts.path.lstrip('/'))).resolve()
        if target.is_dir():
            target = target / 'index.html'
        checked += 1
        if not target.exists():
            errors.append(f'{rel}: broken link "{ref}"')

placeholders = sorted({p.relative_to(ROOT).as_posix() for ext in ('*.html', '*.xml', '*.txt')
                       for p in ROOT.rglob(ext) if '.git' not in p.parts and 'docs' not in p.parts
                       and 'YOUR-DOMAIN.com' in p.read_text(encoding='utf-8', errors='ignore')})
if placeholders:
    warnings.append(f'YOUR-DOMAIN.com is still used in {len(placeholders)} file(s). Run scripts/set-domain.ps1 '
                    'with your real address before launch, or canonical URLs and the sitemap point to a placeholder.')

if len(canonical_hosts) > 1:
    errors.append('Indexable pages use multiple canonical hosts: ' + ', '.join(sorted(canonical_hosts)))
sitemap_path = ROOT / 'sitemap.xml'
if sitemap_path.is_file():
    sitemap_hosts = {urlsplit(loc).netloc.lower() for loc in re.findall(r'<loc>(.*?)</loc>', sitemap_path.read_text(encoding='utf-8')) if urlsplit(loc).netloc}
    if canonical_hosts and sitemap_hosts and canonical_hosts != sitemap_hosts:
        errors.append('Sitemap host(s) do not match canonical page host(s): canonical=' + ', '.join(sorted(canonical_hosts)) + '; sitemap=' + ', '.join(sorted(sitemap_hosts)))

print(f'Checked {len(pages)} pages and {checked} local links.')
for w in warnings:
    print('WARNING: ' + w)
if errors:
    print('CHECK FAILED')
    for error in errors:
        print('- ' + error)
    sys.exit(1)
print('All file, link and SEO-tag checks passed. Still test calculators, forms and mobile layout in a browser.')
