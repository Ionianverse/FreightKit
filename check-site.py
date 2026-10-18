#!/usr/bin/env python3
"""Lightweight pre-launch check for required FreightKit files and placeholder domains."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
required = [
    'index.html', 'assets/css/style.css', 'assets/js/app.js', 'tools/index.html',
    'templates/index.html', 'downloads/inventory-tracker.csv', 'downloads/shipment-tracker.csv',
    'contact/index.html', 'contact-handler.php', 'contact-config.example.php',
    '.htaccess', 'sitemap.xml', 'robots.txt', 'README.md'
]
errors = []
for item in required:
    if not (ROOT / item).is_file():
        errors.append(f'Missing required file: {item}')
placeholders = []
for ext in ('*.html', '*.xml', '*.txt'):
    for path in ROOT.rglob(ext):
        if '.git' in path.parts:
            continue
        try:
            if 'YOUR-DOMAIN.com' in path.read_text(encoding='utf-8'):
                placeholders.append(str(path.relative_to(ROOT)))
        except UnicodeDecodeError:
            pass
if placeholders:
    errors.append('Replace YOUR-DOMAIN.com before launch in: ' + ', '.join(sorted(set(placeholders))))
if errors:
    print('CHECK FAILED')
    for error in errors:
        print('- ' + error)
    sys.exit(1)
print('Basic file/domain checks passed. This does not replace browser, calculator, form, security, or mobile testing.')
