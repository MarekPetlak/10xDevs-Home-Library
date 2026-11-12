# Research: pagination tooling for browse-and-search-books

Date: 2026-11-12 · Method: Exa web search (not verified against Context7)

## Question

Which pagination approach fits the 20-per-page book list in `plan.md` (Symfony 8.1, Doctrine ORM, Twig, Bootstrap 5, filters kept in the query string, books loaded with fetch-joined authors)?

## Options found

| Option | Symfony 8 | Fit |
|---|---|---|
| Doctrine `Doctrine\ORM\Tools\Pagination\Paginator` | Part of the installed ORM | Handles fetch-joined collections; no new dependency. Bootstrap pager is ~15 lines of Twig that merge `app.request.query.all` with `page`. |
| KnpPaginatorBundle | v6.10.0 allows Symfony 8 | Ships `bootstrap_v5_pagination.html.twig`, `distinct: true` option, links carry current GET params. Sorting and filtering features unused here; needs a config file and `PaginatorInterface`. |
| Pagerfanta + BabDev PagerfantaBundle | Core v4.11.0 (2026-10-29) lists `symfony/cache ^8.1` as dev dependency; the bundle's own Symfony 8 constraint was not confirmed | `QueryAdapter` for Doctrine ORM, `{{ pagerfanta(pager) }}` Twig function. |
| Tiloweb/SymfonyPaginationBundle | Claims Symfony 6.4, 7.x, 8.x | Thin wrapper over Doctrine's Paginator; niche, not worth a dependency. |

## Decision

Keep Doctrine's built-in `Paginator` (as already written in `plan.md`, Phase 2 and 3). Reasons: no new dependency, one list page does not justify a bundle, and the plan already builds a Bootstrap pager that preserves filters.

Revisit only if more paginated lists appear. KnpPaginatorBundle would then be the safest bundle (confirmed Symfony 8 support, ready Bootstrap 5 template); check its install and constraints with Context7 first.

## Sources

- https://github.com/KnpLabs/KnpPaginatorBundle/releases
- https://github.com/KnpLabs/KnpPaginatorBundle/tree/master/templates/Pagination
- https://packagist.org/packages/pagerfanta/pagerfanta
- https://yarnaudov.com/symfony-pagination-example.html
- https://github.com/Tiloweb/SymfonyPaginationBundle
