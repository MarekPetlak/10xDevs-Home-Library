---
change_id: ui-books-list
title: Design-system pass: new layout for all views
status: impl_reviewed
created: 2026-11-13
updated: 2026-11-13
archived_at: null
---

## Notes

UI change started via /10x-ui /books.

- **View:** `/books` (route `book_index`), `templates/book/index.html.twig` is the audited view; the new layout (palette, fonts, icons) applies to all views: base, list, detail, new, login, flash alerts, error pages.
- **Token source:** Bootstrap 5.3.8 CSS variables (`--bs-*`, `data-bs-theme`), overrides in `assets/styles/app.css`
- **Contract variant:** existing design system (Bootstrap). Extend it; supplied palette replaces Bootstrap defaults through existing variables (no second palette, no `shadcn init`). Approved dependencies: `doctrine/doctrine-fixtures-bundle` (dev), `@playwright/test` (dev, local), `symfony/ux-icons` (runtime, Bootstrap Icons set).
- **Palette and fonts:** see `design-tokens.md` in this folder.
- **Pre-audit (hard-coded scan):** 0 literals in the view. Candidate charges: dead `recommendation-card` class (index.html.twig:20), deprecated `text-muted` (4x here, 3x in show.html.twig), bare-paragraph empty/no-results states (lines 59-62), no UI rules in AGENTS.md.
- **Visual gate:** kitchen-sink page, desktop + one mobile width (no Playwright in repo).
