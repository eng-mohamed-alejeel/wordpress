# Editable website policies

The local website has four ordinary WordPress pages: `privacy-policy`, `terms`,
`cookie-policy`, and `reservation-policy`. Edit them in **Pages → All Pages**.
Arabic content uses the page editor. English copy uses **English public content**;
review the translation against the updated Arabic and check its review checkbox.
Content and titles are stored in WordPress, not hardcoded in the page template.

`page-legal.php` supplies presentation and the actual last-modified date. The footer
links only published policy pages and preserves the visitor's language.

`website-policies.json` is the initial editorial seed, not a live content source.
Do not rerun `tools/setup-website-policies.php --apply` after manual editing unless
you intend to replace that content. The local-only script saves the previous posts,
metadata, and privacy-page setting under `.tmp/legal-pages-before-*.json` first.

Editorial references:

- SDAIA privacy policy guidance: https://sdaia.gov.sa/Documents/PrivacyPolicyGuideline.pdf
- Saudi Personal Data Protection Law: https://sdaia.gov.sa/en/SDAIA/about/Documents/Personal%20Data%20English%20V2-23April2023-%20Reviewed-.pdf
- Ministry of Commerce e-commerce resources: https://mc.gov.sa/ar/ECC/pages/default.aspx
- Website cookie manager and existing business contact records.

The copy describes general website use and statutory rights without inventing
refund deadlines, reservation fees, warranties or financing approval. The business
must verify actual retention practices, processors, transfers and transaction
terms against its operations before commercial deployment; this is editorial
content, not a certification of legal compliance.
