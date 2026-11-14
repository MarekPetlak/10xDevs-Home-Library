---
project: "My home library"
version: 1
status: draft
created: 2026-11-02
updated: 2026-11-14
prd_version: 3
main_goal: speed
top_blocker: decisions
milestone_id: first-recommendation-flow
milestone_seq: 1
milestone_status: open
---

# Roadmap: My home library

> Derived from `context/foundation/prd.md` (v3) + auto-researched codebase baseline.
> Edit-in-place; the next milestone continues this file. Archive only when an independent roadmap replaces it.
> Slices below are listed in dependency order. The "At a glance" table is the index.

## Milestone

**M-1: Pierwszy przepływ od logowania do rekomendacji** — Status: open

- **Intent:** Zalogowany domownik przechodzi cały przepływ MVP z PRD: loguje się, dodaje książki do wspólnego zbioru (z ostrzeżeniem o duplikacie), oznacza je jako przeczytane, ocenia i widzi na stronie głównej do trzech rekomendacji. Rezultat jest wdrożony na produkcji.
- **Source materials:** `context/foundation/prd.md` (v3)
- **Done when:** every F-NN and S-NN below is `done`.
- **Scope anchors:** FR-001, FR-003, FR-004, FR-005, FR-006, FR-007, FR-008, FR-010 (wszystkie must-have) oraz US-01.

## Vision recap

Użytkownik nie pamięta, jakie książki ma w domowej bibliotece i które przeczytał, więc planując zakup ryzykuje kupienie drugiego egzemplarza. Ewidencja wspólnego zbioru (co mam, co przeczytałem) zasila regułę rekomendacji kolejnej lektury, która odróżnia produkt od zwykłej bazy książek. Z produktu korzysta 2–4 domowników, każdy z własnym kontem, własnymi ocenami i statusem przeczytania.

## North star

**S-04: Użytkownik widzi do trzech rekomendacji kolejnych książek na stronie głównej** — to najmniejszy przepływ end-to-end, który dowodzi, że produkt robi to, co obiecuje (rekomenduje na podstawie ocen), i jest głównym kryterium sukcesu PRD; przy celu `speed` ma sens dowieźć go jak najwcześniej.

> „North star” oznacza tu: najmniejszy kompletny przepływ widoczny dla użytkownika, którego działanie dowodzi, że główna idea produktu jest słuszna — umieszczony tak wcześnie, jak pozwalają na to jego zależności, bo reszta ma sens tylko wtedy, gdy on działa.

## At a glance

| ID    | Change ID                      | Outcome (user can …)                                                    | Prerequisites | PRD refs      | Status   |
| ----- | ------------------------------ | ----------------------------------------------------------------------- | ------------- | ------------- | -------- |
| S-01  | sign-in-household-accounts     | user can sign in with e-mail and password and see the logged-in home page | —             | FR-001, US-01 | done |
| S-02  | add-book-to-shared-collection  | user can add a book to the shared collection                            | S-01          | FR-004, US-01 | done |
| S-03  | rate-read-book                 | user can mark a book as read and rate it 1–5                            | S-02          | FR-008, US-01 | done |
| S-04  | home-recommendations           | user can see up to three recommended books on the home page             | S-03          | FR-010, US-01 | done |
| S-05  | browse-and-search-books        | anyone can browse and search books by title, author and category        | S-02          | FR-003        | done |
| S-06  | duplicate-book-warning         | user is warned about a duplicate before adding a book                   | S-02          | FR-005, US-01 | in-progress |
| S-07  | edit-own-book-entry            | user can edit a book they added, after confirmation                     | S-02          | FR-006        | proposed |
| S-08  | soft-delete-own-book           | user can delete a book they added without losing others' ratings        | S-02, S-03    | FR-007        | proposed |
| S-09  | reader-comments-privacy           | comment author can mark a reader comment public or private                    | S-03          | FR-009        | proposed |

## Baseline

What's already in place in the codebase as of `2026-11-02` (auto-researched + user-confirmed).
Foundations below assume these are present and do NOT re-scaffold them.

- **Frontend:** partial — szablon bazowy i mechanizm zasobów zainstalowane (`templates/base.html.twig`), brak widoków aplikacji; użytkownik planuje użyć biblioteki Bootstrap CSS do budowy interfejsu.
- **Backend / API:** partial — szkielet aplikacji działa, jedyny kontroler to `/healthz` (`src/Controller/HealthController.php`).
- **Data:** partial — ORM i migracje zainstalowane, baza zewnętrzna skonfigurowana (`config/packages/doctrine.yaml`, `.env`), brak encji i migracji (`src/Entity/`, `migrations/` puste); migracje uruchamiają się przy wdrożeniu (`fly.toml` `release_command`).
- **Auth:** partial — komponent bezpieczeństwa zainstalowany, ale tylko dostawca in-memory, bez logowania (`config/packages/security.yaml`).
- **Deploy / infra:** present — wdrożone na produkcję, CI testuje i wdraża po merge (`.github/workflows/deploy.yml`, `fly.toml`, `context/deployment/deploy-plan.md`); otwarte: kopie zapasowe bazy, własna domena (pominięta).
- **Observability:** partial — logowanie i punkt `/healthz`; użytkownik planuje podłączyć zewnętrzne narzędzie do śledzenia błędów (poza PRD, patrz Parked).

## Foundations

Brak. Warstwy oznaczone w Baseline jako `partial` (dane, auth, interfejs) są wprowadzane minimalnie w pierwszym wycinku, który ich potrzebuje (S-01 dla logowania i pierwszej migracji, S-02 dla encji książki), zgodnie z regułą stopniowego ujawniania. Żadne przekrojowe zadanie nie blokuje zaplanowania, weryfikacji ani bezpieczeństwa S-01.

## Slices

### S-01: Logowanie kont domowników

- **Outcome:** user can sign in with e-mail and password and see the logged-in home page (konta zakłada właściciel ręcznie; niezalogowany nie ma dostępu do funkcji osobistych).
- **Change ID:** sign-in-household-accounts
- **PRD refs:** FR-001, US-01
- **Prerequisites:** —
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:**
  - Jak właściciel zakłada konta i kto nim jest (poza aplikacją czy ekran administracyjny) — Owner: user. Block: no.
- **Risk:** Pierwszy wycinek, bo wszystkie pozostałe wymagają zalogowanego użytkownika; wprowadza pierwszą migrację na produkcyjnej bazie i hasła muszą być przechowywane bezpiecznie, a sesja przetrwać restart aplikacji (NFR).
- **Status:** done

### S-02: Dodawanie książki do wspólnego zbioru

- **Outcome:** user can add a book to the shared collection (tytuł i autor wymagane i walidowane, ISBN, rok, notatka, kategoria, do około pięciu tagów).
- **Change ID:** add-book-to-shared-collection
- **PRD refs:** FR-004, US-01
- **Prerequisites:** S-01
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:**
  - Czy kategoria pochodzi ze stałej listy, czy jest wolnym tekstem, i jakie są kategorie — Owner: user. Block: yes. Resolved: stała lista w `App\Enum\Category`.
  - Czym jest „rok” w polach książki — rok dodania czy rok wydania — Owner: user. Block: no. Resolved: rok wydania, wymagany.
  - Do czego służą tagi i notatka książki i czy to must-have w MVP — Owner: user. Block: no. Resolved: tagi jako encje (do pięciu); notatka książki wypada, notatki czytelników patrz pytanie 11.
- **Risk:** Wprowadza encję książki i kategorię, od której zależy reguła rekomendacji, dlatego kategorie muszą być rozstrzygnięte przed planowaniem; to najdłuższa gałąź prowadząca do north star.
- **Status:** done

### S-03: Oznaczanie jako przeczytana i ocena

- **Outcome:** user can mark a book as read and rate it 1–5 within their own account (oceny i status są osobiste; ocena jest widoczna dla innych zalogowanych tylko obok komentarza swojego autora; data oznaczenia jest zapisywana; rekord przeczytania nie podlega edycji).
- **Change ID:** rate-read-book
- **PRD refs:** FR-008, US-01
- **Prerequisites:** S-02
- **Parallel with:** S-05, S-06, S-07
- **Blockers:** —
- **Unknowns:**
  - Komentarze czytelników (wiele na książkę, publiczne lub prywatne wg autora komentarza) razem z oceną — patrz Open Roadmap Question 11 — Owner: user. Block: no. Resolved: komentarz jest opcjonalnym polem formularza oznaczania jako przeczytanej (jeden na rekord przeczytania, bez edycji; usuwany razem z rekordem), widoczny dla wszystkich zalogowanych (e-mail autora zaciemniony); prywatność komentarzy przenosi S-09.
- **Risk:** Data oznaczenia jako przeczytana jest wejściem reguły „ostatni rok”, więc musi być zapisywana od początku, a nie dopisywana później; błąd w izolacji ocen (poza oceną pokazywaną obok komentarza jej autora) naruszyłby guardraile prywatności.
- **Status:** done

### S-04: Rekomendacje na stronie głównej

- **Outcome:** user can see up to three recommended books on the home page, chosen by the PRD rule (najwyższe średnie oceny, kategorie najczęściej czytane w ostatnim roku, nieprzeczytane; druga kategoria jako uzupełnienie), or a message when none can be generated.
- **Change ID:** home-recommendations
- **PRD refs:** FR-010, US-01
- **Prerequisites:** S-03
- **Parallel with:** S-05, S-06, S-07
- **Blockers:** —
- **Unknowns:**
  - Przewidywany ruch (qps) i wolumen danych — Owner: user. Block: no. Resolved: 2–3 qps w MVP (później do 10), do 10 000 książek.
- **Risk:** To north star i najbardziej ryzykowna logika domenowa; strona musi się ładować poniżej 2 sekund (NFR), a reguła zależy od spójnych kategorii z S-02.
- **Status:** done

### S-05: Przeglądanie i wyszukiwanie książek

- **Outcome:** anyone, also when not logged in, can browse and search books by title, author and category (bez ocen, komentarzy i statusu przeczytania).
- **Change ID:** browse-and-search-books
- **PRD refs:** FR-003
- **Prerequisites:** S-02
- **Parallel with:** S-03, S-04, S-06, S-07
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Poza north star, więc po ścieżce rekomendacji; trzeba pilnować, by widok publiczny nie ujawniał danych osobistych.
- **Status:** done

### S-06: Ostrzeżenie o duplikacie

- **Outcome:** user is warned about a duplicate (tytuł i autor bez względu na wielkość liter i zbędne spacje, oraz ISBN) before a book is saved, and decides whether to add it anyway.
- **Change ID:** duplicate-book-warning
- **PRD refs:** FR-005, US-01
- **Prerequisites:** S-02
- **Parallel with:** S-03, S-04, S-05, S-07
- **Blockers:** —
- **Unknowns:**
  - Czy ISBN jest polem wymaganym i jak łączą się dopasowania po ISBN oraz po tytule i autorze — Owner: user. Block: no.
- **Risk:** Bezpośrednio odpowiada na ból z Vision (kupienie drugiego egzemplarza), ale nie blokuje rekomendacji, więc jest po north star.
- **Status:** in-progress

### S-07: Edycja własnej książki

- **Outcome:** user can edit a book they added, after confirming the change; other users cannot edit it.
- **Change ID:** edit-own-book-entry
- **PRD refs:** FR-006
- **Prerequisites:** S-02
- **Parallel with:** S-03, S-04, S-05, S-06
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Wymaga sprawdzenia, że tylko autor wpisu może edytować; edycja nie może gubić ocen i komentarzy (guardrail).
- **Status:** proposed

### S-08: Usuwanie własnej książki

- **Outcome:** user can delete a book they added; the book disappears from all lists while other users' ratings and comments stay preserved.
- **Change ID:** soft-delete-own-book
- **PRD refs:** FR-007
- **Prerequisites:** S-02, S-03
- **Parallel with:** S-04, S-05, S-06, S-07
- **Blockers:** —
- **Unknowns:**
  - Co dzieje się z książkami, gdy konto ich autora zostanie usunięte — Owner: user. Block: no.
- **Risk:** Wymaga S-03, żeby dało się zweryfikować, że cudze oceny przetrwały usunięcie; usunięta książka nie może wpływać na rekomendacje.
- **Status:** proposed

### S-09: Prywatność komentarzy czytelników

- **Outcome:** the author of a reader comment (the comment field of their read record) can mark it public or private; a private comment is hidden from every other user together with its author's rating, which S-03 shows next to the comment (S-03 ships comments visible to all signed-in users with an obfuscated e-mail).
- **Change ID:** reader-comments-privacy
- **PRD refs:** FR-009
- **Prerequisites:** S-03
- **Parallel with:** S-04, S-05, S-06, S-07, S-08
- **Blockers:** —
- **Unknowns:**
  - Domyślna widoczność nowego komentarza i los już zapisanych komentarzy (publiczne czy prywatne) — Owner: user. Block: no.
  - Czy zaciemniać e-mail autora także w komentarzach publicznych — Owner: user. Block: no.
- **Risk:** Do czasu tej zmiany komentarze są widoczne dla wszystkich zalogowanych, co przeczy regule PRD o osobistych komentarzach; zmiana wymaga migracji istniejących komentarzy.
- **Status:** proposed

## Backlog Handoff

| Roadmap ID | Change ID                     | Suggested issue title                                      | Ready for `/10x-plan` | Notes |
| ---------- | ----------------------------- | ---------------------------------------------------------- | --------------------- | ----- |
| S-01       | sign-in-household-accounts    | Logowanie kont domowników (e-mail + hasło)                 | yes                   | Run `/10x-plan sign-in-household-accounts` |
| S-02       | add-book-to-shared-collection | Dodawanie książki do wspólnego zbioru                      | yes                   | S-02 jest zaimplementowane w zmianie add-book-to-shared-collection |
| S-03       | rate-read-book                | Oznaczanie książki jako przeczytanej i ocena 1–5           | no                    | Czeka na S-02 |
| S-04       | home-recommendations          | Rekomendacje na stronie głównej (north star)               | no                    | Czeka na S-03 |
| S-05       | browse-and-search-books       | Przeglądanie i wyszukiwanie książek                        | no                    | Czeka na S-02 |
| S-06       | duplicate-book-warning        | Ostrzeżenie o duplikacie przy dodawaniu książki            | no                    | Czeka na S-02 |
| S-07       | edit-own-book-entry           | Edycja własnej książki z potwierdzeniem                    | no                    | Czeka na S-02 |
| S-08       | soft-delete-own-book          | Usuwanie własnej książki bez kasowania cudzych ocen        | no                    | Czeka na S-02 i S-03 |
| S-09       | reader-comments-privacy          | Prywatność komentarzy czytelników (publiczna lub prywatna)    | no                    | Czeka na S-03 |

## Open Roadmap Questions

1. **Czym jest „rok” w polach książki** — rok dodania do zbioru czy rok wydania? — Owner: user. Block: S-02. Resolved: rok wydania, pole wymagane.
2. **Czy kategoria pochodzi ze stałej listy, czy jest wolnym tekstem, i jakie są kategorie?** Wybór wpływa na zliczanie „najczęściej czytanej” kategorii. — Owner: user. Block: S-02, S-04, S-05. Resolved: stała lista w kodzie (`App\Enum\Category`).
3. **Do czego służą tagi i notatka książki?** Reguła rekomendacji ich nie używa; czy to must-have w MVP? — Owner: user. Block: S-02. Resolved: tagi są encjami, do pięciu na książkę; notatka książki wypada (zastępują ją notatki czytelników, pytanie 11).
4. **Jak właściciel zakłada konta i kto nim jest** (poza aplikacją czy ekran administracyjny). — Owner: user. Block: S-01.
5. **Co dzieje się z książkami, gdy konto ich autora zostanie usunięte.** — Owner: user. Block: S-08.
6. **Czy ISBN jest polem wymaganym i jak łączą się dopasowania po ISBN oraz po tytule i autorze** przy ostrzeżeniu o duplikacie. — Owner: user. Block: S-06. Resolved: ISBN jest wymagany i walidowany; zasady łączenia dopasowań dla duplikatu rozstrzyga S-06.
7. **Jaki jest przewidywany ruch (qps)?** — Owner: user. Block: S-04. Resolved: 2–3 qps w MVP, później do 10 qps.
8. **Jaki jest przewidywany wolumen danych (data_volume)?** — Owner: user. Block: S-04. Resolved: nie więcej niż 10 000 książek (liczba ocen nie podana).
9. **Czy podłączyć zewnętrzne narzędzie do śledzenia błędów w tym kamieniu milowym?** Użytkownik zgłosił taki zamiar, ale PRD go nie zawiera; jeśli ma wejść do zakresu, dopisz wymaganie do PRD. — Owner: user. Block: roadmap-wide.
10. **Kopie zapasowe produkcyjnej bazy** (otwarta faza w planie wdrożenia) przed pierwszymi danymi domowników; guardrail PRD mówi, że dane nie giną. — Owner: user. Block: roadmap-wide.
11. **Komentarze czytelników** (decyzja właściciela): komentarze wchodzą razem z oceną w S-03 (FR-008/FR-009): wiele komentarzy na książkę, dodawanych przez czytelników, każdy oznaczony jako publiczny lub prywatny przez jej autora. Przeczy to regule PRD „komentarze są osobiste / ukryte przed niezalogowanymi” (FR-009, Access Control, Guardrails), dopóki PRD nie zostanie zaktualizowane przy planowaniu S-03. — Owner: user. Block: S-03. Resolved (2026-11-10): S-03 dodaje komentarz od razu jako pole formularza oznaczania jako przeczytanej, widoczny dla wszystkich zalogowanych z zaciemnionym e-mailem autora; flaga publiczna/prywatna wchodzi osobno jako S-09 (`reader-comments-privacy`); PRD zaktualizowano do wersji 3 w fazie 5 planu S-03 (FR-009 awansowany do must-have, reguła osobistych komentarzy czasowo złagodzona tylko dla komentarzy).

## Parked

- **Reset hasła (FR-002, nice-to-have)** — Why parked: cel `speed`; w MVP wystarczy ręczna zmiana hasła przez właściciela (PRD, uzasadnienie FR-002).
- **Zewnętrzne śledzenie błędów** — Why parked: zamiar użytkownika bez pokrycia w PRD (patrz Open Roadmap Question 9); nie jest wymyślany jako wycinek.
- **Samodzielna rejestracja kont** — Why parked: PRD Non-Goals; konta zakłada właściciel ręcznie.
- **Pobieranie danych książki z zewnętrznego serwisu po ISBN** — Why parked: PRD Non-Goals; dane wpisuje użytkownik.
- **Rekomendacje oparte na podobieństwie gustu między użytkownikami** — Why parked: PRD Non-Goals; zostaje reguła ze średnią ocen i kategorią.
- **Wiele oddzielnych bibliotek lub gospodarstw domowych** — Why parked: PRD Non-Goals; jeden wspólny zbiór.
- **Import wielu książek naraz** — Why parked: PRD Non-Goals; książki dodawane pojedynczo.
- **Notatki AI jako dodatkowy sygnał rekomendacji i uzasadnienie rekomendacji** — Why parked: PRD Success Criteria (Secondary), poza kamieniem milowym przy celu `speed`.
- **Własna domena** — Why parked: plan wdrożenia pomija ją; aplikacja działa na domenie platformy.

## Milestone History

(Append-only. Carried forward verbatim into each successor milestone's roadmap; empty on the very first milestone.)

## Done

(Empty on first generation. `/10x-archive` appends an entry here — and flips that item's `Status` to `done` — when a change whose `Change ID` matches the item is archived.)

- **S-01: user can sign in with e-mail and password and see the logged-in home page (konta zakłada właściciel ręcznie; niezalogowany nie ma dostępu do funkcji osobistych).** — Archived 2026-11-03 → `context/archive/2026-11-02-sign-in-household-accounts/`. Lesson: —.
- **S-02: user can add a book to the shared collection (tytuł i autor wymagane i walidowane, ISBN, rok, notatka, kategoria, do około pięciu tagów).** — Archived 2026-11-03 → `context/archive/2026-11-03-add-book-to-shared-collection/`. Lesson: —.
- **S-03: user can mark a book as read and rate it 1–5 within their own account (oceny i status są osobiste; ocena jest widoczna dla innych zalogowanych tylko obok komentarza swojego autora; data oznaczenia jest zapisywana; rekord przeczytania nie podlega edycji).** — Archived 2026-11-10 → `context/archive/2026-11-10-rate-read-book/`. Lesson: —.
- **S-04: user can see up to three recommended books on the home page, chosen by the PRD rule (najwyższe średnie oceny, kategorie najczęściej czytane w ostatnim roku, nieprzeczytane; druga kategoria jako uzupełnienie), or a message when none can be generated.** — Archived 2026-11-12 → `context/archive/2026-11-12-home-recommendations/`. Lesson: —.
- **S-05: anyone, also when not logged in, can browse and search books by title, author and category (bez ocen, komentarzy i statusu przeczytania).** — Archived 2026-11-12 → `context/archive/2026-11-12-browse-and-search-books/`. Lesson: —.
