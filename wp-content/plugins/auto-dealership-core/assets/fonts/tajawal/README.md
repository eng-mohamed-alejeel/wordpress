# Tajawal local font assets

Downloaded 2026-10-04 from the official Google Fonts source:
https://github.com/google/fonts/tree/main/ofl/tajawal

Weights: ExtraLight 200, Light 300, Regular 400, Medium 500, Bold 700,
ExtraBold 800 and Black 900. All are normal-style TrueType files.
`OFL.txt` contains the original SIL Open Font License and copyright notice.

The local `../../css/typography.css` declares these files with font-display: swap.
No Google Fonts request is needed at runtime. The shared Typography class and
the optional system MU loader register the same stylesheet handle once.
