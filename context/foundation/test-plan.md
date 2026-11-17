# Test Plan

> Etapowe wdrożenie testów dla tego projektu. Strategia jest zamrożona na górze
> (§1–§5); wzorce cookbooka na dole (§6) uzupełniają się w miarę dostarczania faz.
> Przeczytaj przed napisaniem jakiegokolwiek nowego testu.
>
> Refresh: uruchom ponownie `/10x-test-plan --refresh`, gdy plan się zestarzeje (zobacz §8).
>
> Last updated: 2026-11-17

## 1. Strategy

Testy w tym projekcie opierają się na trzech zasadach, których nie wolno łamać:

1. **Cost × signal.** Wygrywa najtańszy test, który daje rzeczywisty sygnał dla
   danego ryzyka. Nie awansuj do e2e dlatego, że „wydaje się bezpieczniej". Nie nakładaj
   modelu wizji na deterministyczny diff, który już wykrywa regresję.
2. **Obawy użytkownika są dowodem.** Ryzyka zakotwiczone w „zespół obawia się X"
   mają taką samą wagę jak linie PRD lub dane hot-spotów.
3. **Risks are scenarios, not code locations.** Ten plan dokumentuje *co może
   zawieść* i *dlaczego uważamy to za prawdopodobne* — na podstawie dokumentów,
   wywiadu i *sygnału* z bazy kodu (zmiany, struktura, baza testów). NIE twierdzi,
   która linia odpowiada za awarię. Tę wiedzę dostarcza `/10x-research` w każdej
   fazie wdrożenia. Jeśli plan i badanie nie zgadzają się co do miejsca awarii,
   prawdą jest badanie.

Zakres hot-spotów użyty do ważenia prawdopodobieństwa: `src`, `templates`, `assets`, `migrations` (ostatnie 30 dni, 37 commitów).

Oracle problem: oczekiwana wartość w asercji musi pochodzić z niezależnego źródła
(PRD, kontrakt, wywiad), nigdy z implementacji pod testem.

## 2. Risk Map

Najważniejsze scenariusze awarii, uporządkowane według wpływ × prawdopodobieństwo.
Kolumna Source cytuje *dowody, które podniosły ryzyko*, nigdy plik jako „miejsce awarii".

| # | Risk (failure scenario) | Impact | Likelihood | Source (evidence — not anchor) |
|---|-------------------------|--------|------------|--------------------------------|
| 1 | Zalogowany domownik edytuje lub usuwa książkę dodaną przez kogoś innego | High | Medium | interview Q1; PRD FR-006, FR-007, Access Control; roadmap S-07/S-08 (nowe punkty wejścia jeszcze nie istnieją); hot-spot dir `src/Controller` (22 commits/30d) |
| 2 | Użytkownik usuwa lub zmienia cudzy komentarz albo rekord przeczytania | High | Medium | interview Q1; PRD Guardrails („nie może edytować ani usuwać cudzych ocen i komentarzy"); PRD Access Control (rekord nieedytowalny) |
| 3 | Dane osobiste wyciekają: anonim widzi oceny, komentarze lub rekomendacje; zalogowany widzi cudzą ocenę bez komentarza albo niezaciemniony e-mail autora komentarza | High | Medium | PRD NFR i Guardrails; roadmap S-09 (migracja istniejących komentarzy); hot-spot dir `templates/book` (25 commits/30d) |
| 4 | Usunięcie lub edycja książki gubi cudze oceny i komentarze albo usunięta książka wpływa na rekomendacje i ostrzeżenia o duplikatach | High | Medium | PRD FR-007; roadmap S-08 (Risk); hot-spot dir `src/Repository` (18 commits/30d) |
| 5 | Ostrzeżenie o duplikacie nie zadziała (inna pisownia, ISBN-10 vs ISBN-13) albo ostrzega bez powodu | Medium | High | interview Q3; PRD FR-005; roadmap S-06; hot-spot dir `src/Repository` (18 commits/30d) |
| 6 | Strona główna rekomenduje przeczytaną książkę, pomija okno „ostatniego roku", pokazuje więcej niż trzy pozycje albo pusty ekran zamiast komunikatu | Medium | Medium | PRD Business Logic i FR-010; roadmap S-04 (Risk) |
| 7 | Zestaw testów jest zielony, a prawdziwa regresja przechodzi, bo asercje powtarzają implementację | High | Medium | interview Q4 (brak testów mutacyjnych); oracle problem dla testów pisanych przez AI |

### Risk Response Guidance

| Risk | What would prove protection | Must challenge | Context `/10x-research` must ground | Likely cheapest layer | Anti-pattern to avoid |
|------|-----------------------------|----------------|--------------------------------------|-----------------------|-----------------------|
| #1 | Użytkownik B dostaje odmowę przy edycji i usunięciu książki użytkownika A, a dane w bazie się nie zmieniają; niezalogowany jest przekierowany | „Przycisk jest ukryty w szablonie" nie oznacza, że serwer odrzuci żądanie | Punkty wejścia edycji i usuwania, kształt autoryzacji, token CSRF, sposób ustalania autora wpisu | integration (functional, dwóch użytkowników) | Test tylko dla właściciela; asercja kodu odpowiedzi bez sprawdzenia stanu bazy |
| #2 | Cudzy rekord przeczytania i komentarz nie dają się usunąć; własny rekord można tylko usunąć, nie edytować | „Usuwanie przez własny przycisk" nie dowodzi, że obcy identyfikator jest odrzucany | Trasa usuwania rekordu, token CSRF, sposób wiązania rekordu z użytkownikiem | integration | Podmiana tylko ID książki zamiast ID rekordu |
| #3 | Odpowiedź dla każdej roli (anonim, inny użytkownik, autor) zawiera dokładnie dozwolony zakres danych | „Szablon ukrywa pole" nie oznacza, że dane nie trafiły do odpowiedzi | Widoki i punkty zwracające oceny, komentarze i status; zaciemnianie e-maili; plan migracji S-09 | integration (treść odpowiedzi per rola) | Snapshot całego HTML bez znaczenia; sprawdzenie tylko jednej roli |
| #4 | Po usunięciu i edycji cudze oceny i komentarze nadal istnieją, a książka znika z list, rekomendacji i sprawdzenia duplikatów | „Książka zniknęła z listy" nie dowodzi, że oceny przetrwały ani że rekomendacje jej nie widzą | Mechanizm soft delete (S-08), wszystkie zapytania czytające książki, relacje do rekordów przeczytania | integration z bazą | Mockowanie repozytorium; sprawdzanie tylko listy książek |
| #5 | Pary różniące się wielkością liter, spacjami lub formą ISBN są wykrywane, a różne książki nie wywołują fałszywego ostrzeżenia; decyzja należy do użytkownika | Dopasowanie po tytule i autorze osobno nie wystarcza; reguła łączy tytuł, autora i ISBN | Logika porównania, zbiór ISBN, miejsce, gdzie filtr soft delete musi wejść | unit (reguła) + integration (zapytanie) | Oczekiwana wartość skopiowana z kodu produkcyjnego |
| #6 | Dla przygotowanych danych wynik to maks. trzy nieprzeczytane książki z najczęściej czytanej kategorii, uzupełnione drugą kategorią, albo komunikat | „Rekomendacja się pokazuje" nie dowodzi, że spełnia regułę; sprawdzaj granice: dokładnie rok, remisy, brak danych | Źródło daty przeczytania, sposób liczenia średniej, remisy | unit / integration | Asercja wyliczona tym samym algorytmem co produkcyjny |
| #7 | Celowa zmiana (mutacja) w logice autoryzacji, duplikatów i rekomendacji powoduje czerwony test | „Wysokie pokrycie" nie oznacza silnych asercji | Które testy już istnieją i które mutanty przeżywają | mutation testing (kategoria narzędzi) | Ślepe gonienie za wynikiem mutacji w kodzie szkieletowym |

## 3. Phased Rollout

Każdy wiersz to osobna faza wdrożenia, która otworzy własny folder zmiany przez `/10x-new`.

| # | Phase name | Goal (one line) | Risks covered | Test types | Status | Change folder |
|---|------------|-----------------|---------------|------------|--------|---------------|
| 1 | Własność i prywatność | Obcy użytkownik nie zarządza cudzą książką, rekordem ani komentarzem, a dane osobiste widzi tylko uprawniony | #1, #2, #3 | integration (functional, wiele ról) | change opened | testing-ownership-and-privacy |
| 2 | Trwałość danych i duplikaty | Soft delete i edycja nie gubią cudzych danych, a ostrzeżenie o duplikacie działa po wdrożeniu filtra usuniętych | #4, #5 | integration z bazą, unit | not started | — |
| 3 | Reguła rekomendacji | Granice reguły: rok, remisy, limit trzech, brak danych | #6 | unit, integration | not started | — |
| 4 | Jakość testów i bramki | Testy mutacyjne ujawniają słabe asercje; bramka CI dla testów i analizy statycznej | #7 | mutation testing, gates | not started | — |

Uwaga: fazy 1 i 2 zależą od S-07/S-08/S-09 z roadmapy. Jeśli kod tych zmian jeszcze nie istnieje, faza testuje stan bieżący, a `/10x-research` oznacza, co czeka na S-07/S-08/S-09.

## 4. Stack

| Layer | Tool | Version | Notes |
|-------|------|---------|-------|
| unit + integration | PHPUnit | 13.4.3 | 30 plików w `tests/` (Controller, Service, Repository, Entity, Dto, Command); fail on deprecations/notices/warnings |
| izolacja bazy | dama/doctrine-test-bundle | v8.7.0 | Każdy test w wycofywanej transakcji, lokalna MariaDB 10.11 |
| framework | Symfony | v8.1.9 | PHP 8.4+ (lokalnie 8.5.11) |
| e2e / wizualne | Playwright | ^1.65.0 | Tylko lokalne zrzuty ekranu, poza CI; zob. §7 |
| lint / analiza statyczna | none yet — see Phase 4 | — | Brak w `vendor/bin` i w CI |
| mutation testing | none yet — see Phase 4 | — | Kategoria narzędzi (np. Infection); `checked: 2026-11-17` (przykład kategorii, niezweryfikowany w dokumentacji). When NOT to use: na kodzie szkieletowym i konfiguracyjnym oraz zanim istnieje zestaw testów celowanych w ryzyka |

Baza testów: `meaningful` (skonfigurowany PHPUnit, 30 plików testowych rozproszonych po warstwach).

**Stack grounding tools (current session):**
- Docs: Context7 dostępny — nie użyty w tej fazie (plan nie wybiera jeszcze API); checked: 2026-11-17
- Search: Exa (`web_search_exa`) dostępny — nie użyty; checked: 2026-11-17
- Runtime/browser: Playwright MCP — not available in current session; checked: 2026-11-17
- Provider/platform: GitHub przez `gh` CLI (CI w GitHub Actions) — istotny dla bramek w fazie 4; checked: 2026-11-17

## 5. Quality Gates

| Gate | Where | Required? | Catches |
|------|-------|-----------|---------|
| unit + integration (`bin/phpunit`) | local + CI | required (już podłączona w CI) | regresje logiki |
| ownership / privacy integration | CI | required after §3 Phase 1 | obejście kontroli własności i wyciek danych |
| mutation testing | local, CI optional | required after §3 Phase 4 | słabe asercje |
| lint + analiza statyczna | local + CI | required after §3 Phase 4 | dryf składniowy i typów |
| `composer audit` | CI | required after §3 Phase 4 | podatne zależności |

Hook po edycji jest zalecany lokalnie, a nie substytutem CI (konfiguracja poza zakresem tego planu). Bramek e2e i wizualnych świadomie nie ma, zob. §7.

## 6. Cookbook Patterns

Jak dodawać testy w tym projekcie. Każda podsekcja jest wypełniana po dostarczeniu odpowiedniej fazy.

### 6.1 Adding a unit test
- TBD — see §3 Phase 2 (wzorzec dla reguły dopasowania duplikatów) i §3 Phase 3 (wzorzec dla reguły rekomendacji).

### 6.2 Adding an integration test
- TBD — see §3 Phase 2 (wzorzec dla zapytań z bazą i zachowania danych po soft delete).

### 6.3 Adding an ownership / authorization test
- TBD — see §3 Phase 1 (wzorzec odmowy dostępu obcemu użytkownikowi i sprawdzania stanu bazy).

### 6.4 Adding a privacy-per-role test
- TBD — see §3 Phase 1 (wzorzec sprawdzania treści odpowiedzi dla anonima, innego użytkownika i autora).

### 6.5 Running mutation testing
- TBD — see §3 Phase 4.

### 6.6 Per-rollout-phase notes
(Opcjonalne. Po każdej fazie `/10x-implement` dopisuje 2–3 linie o niespodziankach.)

## 7. What We Deliberately Don't Test

- **Zrzuty ekranu UI i testy wizualne** — ciągle się psują, a koszt przewyższa sygnał. Istniejący zestaw Playwright zostaje lokalny, bez bramki w CI. Re-evaluate, jeśli interfejs zacznie zawierać logikę, której nie wykrywają testy HTML. (Source: interview Q5.)
- **Infrastruktura (Fly.io, CI/CD, hosting bazy)** — poza zakresem testów aplikacji. Re-evaluate, jeśli produkcja zacznie zawodzić w sposób niewidoczny dla testów. (Source: interview Q5.)

## 8. Freshness Ledger

- Strategy (§1–§5) last reviewed: 2026-11-17
- Stack versions last verified: 2026-11-17
- AI-native tool references last verified: 2026-11-17 (brak narzędzi AI-native w planie; mutation testing to kategoria narzędzi, nie weryfikowana w dokumentacji)

Refresh (`/10x-test-plan --refresh`), gdy:

- nowe ryzyko z top 3 pojawi się w roadmapie lub archive,
- data `checked:` narzędzia jest starsza niż trzy miesiące,
- stos technologiczny się zmieni (np. nowy framework lub runner testów),
- §7 przestanie odpowiadać temu, co uważa zespół.
