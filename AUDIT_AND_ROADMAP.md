# FreightKit — code audit, validation results, and monetisation roadmap

**Build reviewed:** FreightKit static HTML, CSS, JavaScript, and PHP source
**Current review date:** 25 October 2026
**Current canonical host in source:** `https://freightkit.in`

## 1. What is included in this update

### Site and navigation
- Added a Solutions landing page that clearly separates current browser tools from proposed workflow/custom-service concepts. Added it to the primary navigation and sitemap.
- Expanded short or vague page meta descriptions across the tool and guide pages.
- Added a real root homepage (`index.html`) so the root URL no longer depends on a tools subpage.
- Added a searchable calculator directory with a live result count and a clear no-results state.
- Improved mobile menu behaviour, focus visibility, and tested common pages at a 360 px viewport.
- Added a dedicated, compact `/embed/` calculator runtime and updated the Custom Calculator Builder to generate an iframe snippet for it.
- Kept the embed page out of the sitemap and added `noindex,nofollow` so it is not indexed as a duplicate standalone page.

### Calculator correctness and validation
- Converted number inputs to numeric values before calculations; blank values are not silently treated as zero.
- Added or strengthened checks for required values, negative values, zero denominators, whole-number fields, percentages, relationships between counts, service-level factors, supplier rating weights, and units.
- Added protection against invalid/non-finite outputs such as `NaN` and `Infinity`.
- Escaped displayed user and CSV data before inserting it into HTML; CSV exports protect formula-like cell values.
- Added visible step-by-step working and safer error states where applicable.
- Added local result CSV export and print controls to standard calculator results.
- Added stricter CSV parsing and validation for ABC Inventory Analysis, CSV Duplicate Finder, and batch freight-weight processing, including duplicate-header checks, inconsistent row widths, malformed quotes, and numerical overflow. CSV processing occurs in the browser; uploaded records are not sent to a FreightKit server by this static implementation.
- ABC Analysis now includes item-level classifications and a full results CSV; the page limits on-screen rows to keep large results usable.
- The duplicate finder exports the duplicate rows it detects.
- Added input for pallet height in Cartons per Pallet and included it in the stack-height calculation.
- Improved the RFQ comparison so an exact unit-cost tie is shown as a tie rather than incorrectly naming one quote cheaper.
- Reworded important assumptions and limitations on freight class, container loading, customs/import tax, safety stock, CO₂ estimates, LCL/FCL, air/sea, TCO, and other decision-support calculators.
- Updated the freight-class density guide to the 13-band density table used by the tool. This remains an *estimate*, not a substitute for confirming the specific freight classification/item and current carrier rules.

### Custom Calculator Builder
- Supports up to 12 numeric inputs, default/minimum/maximum values, step sizes, units, and labels.
- Includes common presets for CBM, volumetric weight, and landed cost.
- Uses a restricted arithmetic formula parser rather than JavaScript `eval()` or `Function()`.
- Supports arithmetic, parentheses, powers, and selected math functions (`sqrt`, `pow`, `min`, `max`, `abs`, `round`, `floor`, `ceil`, `log`, `exp`), as well as `PI` and `E`.
- Validates variable names, duplicate names, unknown variables, ranges, invalid expressions, division by zero, and non-finite results.
- Supports local-browser saving, share links, JSON configuration export/import, CSV result export, and an embeddable calculator iframe.
- Saved configurations stay in that browser's local storage; they are not synced across devices or users.

### CSV batch calculator
- Added a batch chargeable-weight workflow on the Pro page.
- Supports CSV input with required `length_cm`, `width_cm`, `height_cm`, and `qty` columns, plus optional `actual_kg` and `divisor` columns.
- Includes a sample-file download, row validation, result summary, and result CSV download.
- Users can edit the default divisor and display/export precision. A row-level divisor overrides the default. The divisor and rounding rules remain carrier-specific; users should verify the intended rule.

### Commercial honesty
- Removed the impression that paid subscriptions are already available for purchase.
- Pricing language now identifies Pro/Business prices as concepts to test, not live products.
- The support page explains that a monitored support channel still needs to be configured.
- The contact and suggestions forms now post to `contact-handler.php`, which requires PHP hosting and a configured `contact-config.php` plus working mail delivery. Test delivery on the final host before launch.

## 2. Automated checks performed

The previous build’s checks below are historical results from the earlier review. This update received an additional static/syntax/link/sitemap pass; see the handoff notes for the latest check results.


The following checks were run against the source files in the updated package:

| Check | Result |
|---|---:|
| Calculator pages exercised | 39 (37 example-input calculators and 2 CSV upload tools) |
| Example or CSV-flow checks | 39 |
| Batch-weight CSV checks (valid data, duplicate headers, overflow) | 3 |
| Malformed CSV quote validation | Passed |
| Input-boundary / relationship checks | 5 |
| Custom-builder formula/default and unsafe-expression checks | 3 |
| Embedded CBM calculator default output | 4.8 CBM (120 × 80 × 100 cm × 5) |
| Embedded calculator edited-input output | 8 CBM after changing length to 200 cm |
| RFQ exact-cost tie case | Correctly displays a tie at 4-decimal display precision |
| LCL/FCL cent-level tie case | Correctly displays a tie at cent precision |
| Air/sea case where sea transit is faster | Signed time-value adjustment correctly favours sea in the test case |
| Pallet-height sensitivity test | Reduced estimated layers from 4 to 2 when pallet height was raised in the test case |
| Directory search and no-results state | Passed |
| HTML pages scanned for missing local root-relative references | 60 pages; 0 missing references |
| Sitemap XML parse | Passed; 63 locations in the sitemap, all pointing to existing files |
| `assets/js/app.js` syntax check | Passed |
| Inline JavaScript syntax checks | 61 scripts checked; 0 syntax issues |
| Common-page mobile horizontal overflow test | 7 pages at 360 px viewport; no horizontal overflow detected |
| Mobile navigation toggle | Open/close interaction test passed |

These are automated checks of representative examples, validations, and paths through the UI. They do **not** constitute exhaustive mathematical certification for every formula/input combination, legal or tax advice, carrier-rule verification, penetration testing, or a live-host acceptance test.

## 3. Deployment checklist — do these before promoting FreightKit

1. **Upload this updated ZIP and test the actual published site.** Test the homepage, `/tools/`, several individual tools, `/custom-calculator/`, `/embed/`, CSV download/export, and mobile navigation. The source has been tested locally; this package does not prove the current Tiiny deployment has been updated or that every host setting behaves identically.
2. **Confirm your domain.** The current source uses `https://freightkit.in`. If the live domain changes, run `scripts/set-domain.ps1` and review the resulting diff before launch.
3. **Connect working feedback/support forms.** The contact and suggestion forms use the included PHP handler. On PHP hosting, configure `contact-config.php`, confirm SMTP/mail delivery, and submit a real test enquiry. Static-only hosting cannot run this handler.
4. **Configure a monitored support address.** Do not publish a placeholder support email.
5. **Review the privacy policy and terms.** These pages are draft informational copy, not legal advice. Update them to match the analytics, form provider, payment processor, cookies, and data retention you actually deploy.
6. **Add and test analytics/Search Console only after making the privacy notice accurate.** At minimum track useful tool visits, form submissions, template clicks/downloads, and returning visitors without collecting sensitive shipment or customer data unnecessarily.
7. **Subfolder hosting is supported.** Internal paths are relative since the 21 October 2026 update, so the site works at a domain root or in a subdirectory (for example a GitHub Pages project site).
8. **No checkout is wired.** There are no payment processing, customer accounts, authenticated teams, cloud-saved records, live freight-rate APIs, or active subscriptions in this static package. Do not advertise any of those as available until they are built, connected, and tested.

## 4. Monetisation ideas ranked by practicality

### First experiment: useful spreadsheet products
The current free CSV downloads are useful as lead magnets, but a paid product should solve a richer work problem than a blank CSV. Build and validate one clean Excel/Google Sheets product first:

- Freight quotation comparison with multiple vendors, all-in fee lines, chargeable weight, cost per unit, transit-time comparison, and an audit trail.
- Supplier/RFQ scorecard with editable weights, cost comparison, lead time, quality, and delivery performance.
- Purchase-order and ETA tracker with overdue follow-ups, open-quantity calculations, and an exception dashboard.
- Freight invoice audit workbook comparing quote versus invoice and flagging unexpected accessorial charges.
- Inventory reorder planner combining average demand, lead time, safety stock, MOQ, and open PO quantities.

**Pricing experiments, not validated market prices:** test approximately ₹199–₹499 for a focused single template, ₹499–₹1,499 for a useful bundle, and higher prices only if the workbook saves customers meaningful recurring time. Interview target users and try to obtain a few real purchases before building multiple products.

### Second experiment: embedded calculators
The Custom Calculator Builder can now generate an embeddable calculator. A future monetisation test could offer:
- Free basic embedding with FreightKit branding/backlink.
- Paid branding removal, more variables, team presets, usage controls, or downloadable reports.
- A one-time setup fee for a customised calculator built for a small freight forwarder, importer, or logistics service provider.

This is promising because the basic embed functionality is present, but account entitlements, checkout, server-side licence enforcement, usage measurement, and customer support still need to be built before selling it as a subscription service.

### Third experiment: a real FreightKit Pro workflow
The best paid product is likely not another isolated calculator; it is a workflow that removes repetitive spreadsheet work. Prioritise a multi-quote comparison and freight invoice audit before building an all-purpose SaaS:
- Upload a quote/invoice CSV or workbook.
- Map vendor columns with a small guided step.
- Compare freight, surcharges, total cost, cost per kg/unit, transit time, and quoted validity.
- Flag missing charges or amounts exceeding tolerance.
- Download an Excel/CSV or print-ready report.

Start with local, browser-side file processing to keep the MVP inexpensive, clearly stating that data stays on the user's device. Add server storage only if customers request team access or cross-device history, with appropriate security and privacy work.

### Later, after traffic or customer demand
- Advertising on genuinely useful free pages, subject to ad-network eligibility and visitor quality.
- Affiliate/referral relationships for logistics software or shipping services, clearly disclosed and not presented as neutral recommendations.
- Paid implementation/custom spreadsheet work for small importers and freight-forwarding teams.
- Team seats and integrations only after customers repeatedly ask for them and are prepared to pay.

Do not build a large subscription platform only because the site has a “Pro” page. Validate the narrow use case first.

## 5. Product analytics to guide the next build

Track these events with a privacy-conscious analytics setup:
- Calculator opened and calculation completed.
- Validation errors by tool (without collecting user-entered values).
- Result CSV/print action.
- Batch file processed and rejected-row count (not file contents).
- Custom calculator created, share link copied, embed code copied.
- Template download and pricing-page CTA click.

Review results weekly. A tool with repeat use and export activity is a better candidate for Pro than a tool that gets one visit and no action.

## 6. Assumptions and limitations

- FreightKit is a client-side static site. Browser-side calculations and local-storage saves do not require a database, but they do not provide account sync or team collaboration.
- Freight dimensions, charges, insurance, duty/tax, CO₂ factors, safety-stock assumptions, and freight classifications vary by context. Every tool exposes assumptions where possible; operational decisions should be checked against the relevant carrier, tariff, supplier contract, or customs authority.
- The Tiiny host and any live CDN/cache, domain, HTTPS, Search Console, analytics, or form service settings were not modified by editing this ZIP. Re-upload and acceptance-test the deployed version.

## 7. Changes in the 15 October 2026 review

- Added `/solutions/` to position the site around real logistics and supply-chain workflows without claiming unavailable custom services or integrations.
- Added Solutions to primary navigation across pages and added the page to the sitemap.
- Improved short/missing meta descriptions across pages for clearer search snippets.
- Added editable default divisor and output precision controls to the batch chargeable-weight tool. Row-specific CSV divisor values still take precedence; output precision changes rounding/display, not the calculation.
- Added a heading to the legacy custom-calculator alias page.
- Rechecked syntax, local references, sitemap validity and CSV batch behavior after the changes.

### Latest static verification for this package

- `assets/js/app.js`: JavaScript syntax check passed.
- Inline executable scripts: 62 checked; no syntax errors. JSON-LD blocks were excluded from JavaScript parsing because they are structured data, not executable scripts.
- Local HTML `href`/`src` references: 1,350 checked; 0 missing files.
- Sitemap: XML parsed successfully; 64 locations resolve to files in this package.
- HTML pages: 61; duplicate HTML IDs found: 0.
- Page descriptions: all pages have descriptive meta text after this update.
- A browser-based smoke test could not be completed in this environment because Chromium navigation to the local test server was blocked by the runtime. Treat this as a static validation pass, then complete the deployment checklist against the actual hosting provider.

## 8. Changes in the 21 October 2026 publishing and SEO fix

**Publishing / CSS fix**
- Root cause of unstyled pages after publishing: every page loaded `/assets/css/style.css` and linked to `/tools/` etc. with a leading slash, which only works at a domain root. On GitHub Pages project sites, subfolder uploads and some static hosts the CSS, JavaScript and navigation all broke.
- All 61 pages now use relative paths. `app.js` derives the site base from its own URL; the custom-calculator embed code, the saved-calculation loader and the PHP form redirect no longer assume the domain root.
- Tool pages used an inline 4-column footer that never collapsed on phones; all pages now share one header/footer with the responsive `footer-grid`.
- Added `.nojekyll` (GitHub Pages), a 404 `ErrorDocument`, compression and caching rules, `/embed/` framing for other sites, and blocked public access to `docs/`, `scripts/`, Markdown files and `contact-config.php` in `.htaccess`.

**SEO**
- Open Graph and Twitter Card tags, a 1200×630 share image, favicon (SVG + Apple touch icon), web manifest and theme colour on every page.
- Structured data: Organization + WebSite (home), BreadcrumbList (all indexable pages), WebApplication (calculators), Article (guides). Removed the duplicate generic WebSite block from every page.
- Visible breadcrumbs on tool and guide pages; one-line tool introductions replaced with the fuller page description.
- `noindex` on 404, contact-success, embed and the legacy `/tools/custom-calculator/` alias; removed the incorrect canonical from 404.
- Sitemap rebuilt with `lastmod`; CSV files removed from it. `robots.txt` excludes the form handler and success page.

**New features**
- "Copy share link" on every calculator result: the link stores the inputs in the URL and recalculates automatically when opened.
- "Copy result" copies the result table as text.
- "Related calculators" section on each tool page (internal linking).
- Skip-to-content link, highlighted current navigation item, `/` keyboard shortcut to focus the tools search, styled batch-CSV summary.

**Tooling**
- `scripts/check-site.py` now checks every local link, flags root-relative paths and missing SEO tags.
- `scripts/set-domain.ps1` accepts subfolder addresses (e.g. `https://username.github.io/freightkit`) and makes 404-page links absolute.

## 9. 25 October 2026 usability and workflow update

- Added a local-browser freight quote vs invoice audit with suggested column mapping, tolerance controls, matched-line variances, unmatched-line flags, and result CSV export.
- Added a local-browser PO and shipment exception report with a review date, open quantity, overdue and missing-ETA priorities, exception notes, owner visibility, search/filter controls, and CSV export.
- Added five formula-led Excel workbooks for freight invoice audits, PO/ETA exception tracking, supplier scorecards, freight quote comparisons, and inventory reorder planning. Examples are explicitly illustrative; editable thresholds, weights, and reorder assumptions must be aligned to the user's contracts and operating policy.
- Quote comparisons surface all-in charges, cost per kg and unit, transit, validity, and rank; reorder planning includes open PO quantities, lead time, safety stock, MOQ, and review-period demand.
- Corrected the invoice workbook's incomplete-line status and matched-line totals, surfaced PO quantity/status conflicts, and made the supplier scorecard identify incomplete score rows.
- Added a display-currency selector to monetary calculator results. It changes the symbol only and does not convert amounts.
- Added short, tool-specific interpretation and verification guidance to calculator results.
- Expanded the About, Privacy, Terms, Solutions, and Templates pages to describe current capabilities and boundaries more clearly.
- Fixed domain setup so changing from `freightkit.in` updates existing canonical references, not only the old placeholder.

### Remaining launch dependencies

- Live hosting and DNS were not changed by repository edits. Confirm which build is deployed before announcing the new workflows.
- Contact and suggestion delivery still depends on a real `contact-config.php`, PHP hosting, monitored inbox, and tested email transport. The private production config is intentionally not stored in this repository.
- The browser workflow tools do not provide an ERP connection, live carrier feeds, supplier messaging, cloud storage, user accounts, analytics, or payment checkout. These should be scoped and validated before being marketed as available services.
- Calculator output remains planning support. Review carrier, tariff, tax, safety, and reporting rules with the relevant source and update assumptions as they change.
