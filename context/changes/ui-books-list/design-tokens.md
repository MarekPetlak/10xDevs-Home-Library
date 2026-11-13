# Design tokens for ui-books-list

Raw values supplied by the participant on 2026-11-13 (palette and font definition pasted in chat). This file is the record; `assets/styles/app.css` holds the working copy and must carry a comment pointing here.

## Palette (light only)

| Role | Name | Value | Notes |
| --- | --- | --- | --- |
| Background | Tło | `#F5F2EA` | page background (`--bs-body-bg`) |
| Surface | Powierzchnie | `#FFFFFF` | cards, table, navbar, form controls |
| Text | Tekst | `#282D29` | body text (`--bs-body-color`) |
| Accent | Akcent | `#68775B` | primary actions, active states, "read" status |
| Add-on | Dodatki | `#BD7658` | decorative second accent: icons, rules, highlights |

## Derived value (not supplied, computed for contrast)

| Name | Value | Rule |
| --- | --- | --- |
| Accent text | `color-mix(in srgb, #68775B 90%, #282D29)` = `#627056` | text links on the page background |

## Contrast measured on 2026-11-13 (WCAG relative luminance)

| Pair | Ratio | Verdict for normal text (AA needs 4.5) |
| --- | --- | --- |
| Text on background | 12.53 | pass |
| Text on surface | 14.02 | pass |
| Accent on surface | 4.80 | pass |
| White on accent (primary button label) | 4.80 | pass |
| Accent on background | 4.29 | **fail**: use the derived accent text (4.73 on background, 5.29 on surface) for text |
| Add-on on surface | 3.56 | **fail** for text; usable for icons, borders and large text (3:1) |
| Add-on on background | 3.18 | **fail** for text; same limits |
| Text on add-on | 3.94 | **fail**: no text on add-on fills |

## Fonts (supplied definition)

| Use | Font | Notes |
| --- | --- | --- |
| Headings | DM Serif Display | literary character |
| Body and interface | DM Sans | modern, readable |
| Numeric data | DM Sans with tabular figures | `font-variant-numeric: tabular-nums`; to be verified against the font files |

Source of font files: Fontsource packages `@fontsource-variable/dm-sans` and `@fontsource/dm-serif-display` (SIL Open Font License 1.1), subsets `latin` and `latin-ext` (Polish diacritics are in `latin-ext`). Files are copied into `assets/fonts/` and referenced from `app.css` with relative `url()`.

## Icons

Bootstrap Icons through Symfony UX Icons (`bi:` set, inline SVG, `currentColor`), imported one icon at a time with `php bin/console ux:icons:import`.
