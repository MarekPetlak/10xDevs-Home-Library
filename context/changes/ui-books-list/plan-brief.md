# Design-system pass: new layout for all views — Plan Brief

> Full plan: `context/changes/ui-books-list/plan.md`
> Research: `context/changes/ui-books-list/research.md`
> Tokens: `context/changes/ui-books-list/design-tokens.md` (palette, fonts, contrast numbers)

## What & Why

Scope: **all views** (base layout, list, detail with mark-read form, new-book form, login, flash alerts, error pages); `/books` is the audited view with the full state matrix.

`/books` already renders and has no hard-coded colours, but it was built feature by feature: a dead CSS class, a deprecated muted role, duplicated read-badge markup, an inline 40-line pager, bare-paragraph empty states and no search/table landmarks. This change puts the view on an explicit Bootstrap-based contract, restyles it with the supplied palette (`#F5F2EA`, `#FFFFFF`, `#282D29`, `#68775B`, `#BD7658`) and fonts (DM Serif Display headings, DM Sans text, tabular figures), adds icons to the layout, adds a screenshot gate and leaves a rule so the next agent keeps it.

## Starting Point

Symfony + Twig + AssetMapper + Bootstrap 5.3.8; `assets/styles/app.css` is empty; no partials, no fixtures, no Node, no Playwright; `AGENTS.md` has no UI rule. `BookListControllerTest` pins the selectors and texts the markup must keep.

## Desired End State

The app uses the supplied palette and fonts through Bootstrap variables, shows inline SVG icons in the navbar and list, and every text pair meets AA contrast. The list renders from Bootstrap roles and three shared partials, the empty library and no-results pages look different and have clear actions, the search form and table are labelled, `npm run e2e` compares every matrix state at desktop and 390 px against committed baselines, and `AGENTS.md` states the UI rules.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Design system | Extend Bootstrap 5.3.8; overrides in `app.css` | The repo already has it; a second system is refused by the skill | Research |
| Palette | Supplied 5 colours mapped onto Bootstrap variables, light only | Replaces defaults through existing roles instead of adding a second palette | Participant |
| Contrast | Derived darker accent text for links; add-on `#BD7658` only on icons, rules, large text | Accent is 4.29:1 on the background and add-on 3.18-3.56:1, both below 4.5:1 for small text | Plan (measured) |
| Fonts | Self-hosted DM Sans + DM Serif Display, `latin` and `latin-ext` | No external requests; Polish diacritics live in `latin-ext` | Participant + Plan |
| Icons | Symfony UX Icons with the Bootstrap Icons set, inline SVG | No font or JS, follows `currentColor`, auto `aria-hidden`; a runtime Composer dependency approved by the participant | Participant + Plan |
| Muted role | `text-muted` to `text-body-secondary` in the list only | Deprecated since 5.3.0, removed in v6; same value today | Research (verified in docs) |
| Scope | Every view complies with the new layout; `/books` gets the full audit | Participant request; palette, fonts and icons are global anyway | Participant |
| Test-pinned markup | Keep Bootstrap semantic classes; remap their colours via variables | `.badge.text-bg-success`, `.alert-*`, `.invalid-feedback` are asserted by tests | Plan |
| Error pages | Branded `error.html.twig` extending the base layout | No override exists, so 404/500 show Symfony's default page | Plan |
| Dark mode | Out of scope, light-only | Keeps one view, no `base.html.twig` change | Plan |
| Pager | Extracted to `_pager.html.twig` | Ready for reuse; pager tests guard the move | Plan |
| Empty states | One `_empty_state.html.twig`, two variants | Different headings/actions per case, texts stay verbatim for tests | Plan |
| Visual gate | Playwright, local only, dev dependency | Automatic comparison without touching the Node-free image or CI | Plan |
| Test data | `doctrine-fixtures-bundle` (dev) | Standard Symfony fixtures; participant approved the dependency | Plan |

## Scope

**In scope:** all views, three partials, landmarks, `app.css` palette/font/role layer, icons in `base.html.twig` and the list, Playwright spec and baselines (plus `/login` and detail sanity shots), fixtures, `AGENTS.md` UI rule.

**Out of scope:** dark mode, new features or behaviour changes on any view, a second palette, icon fonts or CDN assets, `show.html.twig` restyle (3 `text-muted` stay), CI job for screenshots, icons, controller or data changes.

## Architecture / Approach

Order follows `/10x-ui`: environment and libraries, token values, one view, states and gate. Bootstrap variables are the values; partials under `templates/book/_*.html.twig` are the components; the Playwright spec is the kitchen sink and the gate.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Environment and libraries | Fixtures bundle, UX Icons, font files, fixtures, Playwright config, `AGENTS.md` Node exception | Fixture load purges the database; must hit the test DB only |
| 2. Token values | Palette, fonts, tabular figures, `text-body-secondary`, dead class removed | Bootstrap component variables (buttons, pager, links, focus) must follow the accent, not just `--bs-primary` |
| 3. The views | Three partials, landmarks, icons, detail/forms/login/alerts/error page on the new layout | Moved markup must keep every test selector across all controllers |
| 4. States and gate | 7-state screenshots for `/books`, default and error shots for every other view, UI rule and scan | Baselines drift if updated without explanation |

**Prerequisites:** Node 22 and the Compose MariaDB test database available locally; network access once for `ux:icons:import` and font download.
**Estimated effort:** ~5-6 sessions across 4 phases.

## Open Risks & Assumptions

- AssetMapper must serve CSS under `php -S` with `APP_ENV=test`; verify in phase 1 and adjust the web server command if not.
- DM Sans may not ship tabular figures; phase 2 verifies it and asks you before substituting anything.
- Symfony form theme output (validation feedback, rating radios) is styled only through variables and a few `app.css` rules; phase 3 and the screenshots verify it.
- Error pages cannot be hit in the debug test env the usual way; they are rendered by a test and viewed at `/_error/404` in dev.
- Baselines are created on the local OS; they are not portable to CI, which is why the gate is local only.

## Success Criteria (Summary)

- `php bin/phpunit` passes with the list tests unchanged and new landmark assertions added.
- `npm run e2e` passes against committed baselines for desktop and 390 px.
- A reviewer opening `/books` sees distinct empty-library and no-results states and a visible focus ring on every control.
