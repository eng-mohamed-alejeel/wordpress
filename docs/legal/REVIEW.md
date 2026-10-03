# Legal content preparation — 2026-10-03

The Arabic/English privacy and terms texts in this directory are **review drafts**, not approved legal notices. They were prepared for a Saudi-market dealership context inferred from the site's existing Riyadh/SAR content. The controller identity, markets, contact channels, effective dates and business policies have not been supplied. Pages 3 (`privacy-policy`) and 41 (`terms`) are drafts in local WordPress. The previous WordPress sample privacy text, including a `localhost` URL, was replaced only after an exact-content guard and a local backup; neither page was published.

The privacy outline follows the Saudi Data and AI Authority's [privacy policy preparation guideline](https://dgp.sdaia.gov.sa/wps/portal/pdp/knowledgecenter/details/ElaborationandDevelopingPrivacyPolicyGuideline/%21ut/p/z0/fY3LCsIwEAC_SDZNSTz7KPVBpFDRmovENsbFsAmhBvr3Frx7HBhmQEMHmkxGZ0YMZPzMNy3v-1Uld0XLeHFZCyZLdRLiWHJ2XkJrCQ6g_0tzhSe1UQ50NONrgfQM0FXePEL6nWjY2mx9iEiuSZhNPzXBYz_VHxysR7IQ3_X1C8PpsVw%21/) and the [Personal Data Protection Law implementing regulations](https://dgp.sdaia.gov.sa/wps/portal/pdp/knowledgecenter/details/PDPL2). The terms outline includes transaction information identified by the Saudi [Ministry of Commerce's e-commerce guidance](https://mc.gov.sa/en/mediacenter/news/pages/10-07-19-01.aspx). These sources inform a draft; they do not establish that this particular business, workflow or text is compliant.

Before approval, the business and qualified legal reviewer must complete and verify:

1. Legal entity name in Arabic/English, commercial registration, address, operating markets, official contacts and privacy officer if applicable.
2. Actual personal-data fields, collection sources, processing purposes and lawful basis for each purpose; marketing choices and consent records.
3. Actual processors/providers, their locations, any cross-border transfers and contracts; keep unapproved provider routes disabled.
4. Category-specific retention schedule, rights-request and complaint channels, verification process, cookies/analytics inventory and incident contact.
5. Real reservation/deposit, cancellation/refund, tax/fee, warranty, delivery, financing and dispute terms consistent with approved contracts and system behavior.
6. Arabic and English editorial review, effective dates, version record and final business/legal sign-off.

`tools/prepare-editorial-drafts.php --inspect` reports the guarded local page state; `--apply` prepares the initial content only on the named local development site and never publishes the legal pages. It preserves manually changed pages and will refuse a different existing terms page. The preflight rejects published legal pages with obvious draft markers, WordPress suggested text or local/example links, but the manual legal-approval gate remains mandatory.
