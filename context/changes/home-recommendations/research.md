---
date: 2026-11-12T09:08:13+01:00
researcher: Claude (Sonnet 5.5) for Marek Pętlak
git_commit: fed441d882ab55ce8f4ca71e29446ad2fb77d3e5
branch: main
repository: my-home-library
topic: "home-recommendations — grounding in the codebase + existing libraries covering the requirement"
tags: [research, codebase, recommendations, symfony, doctrine, read-book, home]
status: complete
last_updated: 2026-11-12
last_updated_by: Claude (Sonnet 5.5)
---

# Research: home-recommendations

**Date**: 2026-11-12T09:08:13+01:00
**Git Commit**: fed441d882ab55ce8f4ca71e29446ad2fb77d3e5 (working tree has uncommitted skill/context files only)
**Branch**: main

## Research Question

Zbadaj bazę kodu (stack: `context/foundation/tech-stack.md`) pod kątem uziemienia planowania S-04 `home-recommendations`; następnie sprawdź (Exa), czy istnieje gotowa biblioteka pokrywająca wymagania.

## Summary

- **Nic z rekomendacji nie jest zaimplementowane.** Grep „recommend" w `src`, `templates`, `tests`, `config` nie dał trafień (sprawdzono tylko te cztery katalogi); wzmianki są jedynie w dokumentach (`AGENTS.md`, `context/foundation/prd.md`).
- **Dane wystarczają do reguły z PRD bez zmian schematu**: `read_book` ma `rating`, `read_on`, `user_id`, `book_id`; `book` ma `category` (enum). Brak agregatów AVG/GROUP BY w istniejących repozytoriach (przeanalizowane `ReadBookRepository`, `BookRepository`).
- **Reguła PRD (FR-010, Business Logic, `prd.md:95,105-113`) to deterministyczny ranking po średniej ocen w kategoriach użytkownika, nie filtrowanie kolaboratywne.** Podobieństwo gustów między użytkownikami jest w Non-Goals (roadmap, Parked). Biblioteki collaborative-filtering z wyszukiwania liczą coś innego niż ta reguła.
- **Rekomendacja robocza dla planu: własne zapytanie DQL + mały serwis `RecommendationService`, bez nowej zależności.** Uzasadnienie: reguła jest ścisła i krótka; znalezione biblioteki (patrz niżej) rozwiązują inny problem, a część wymaga bibliotek natywnych lub innej warstwy DB.

## Detailed Findings

### Strona główna

- `src/Controller/HomeController.php:13-17`: trasa `/` (`home`), niezabezpieczona, renderuje szablon bez zmiennych. Strona jest publiczna, a rekomendacje nie mogą być widoczne dla niezalogowanych (PRD, `prd.md:101`), więc kontroler/szablon musi rozgałęziać się po `app.user`.
- `templates/home/index.html.twig:5-12`: blok `body_content`, warunek `{% if app.user %}` w liniach 9-11 (miejsce na blok rekomendacji). Klucze tłumaczeń `home.*`.
- `tests/Controller/HomeControllerTest.php` pokrywa tylko stronę anonimową.
- Wzorzec kontrolera do skopiowania: `src/Controller/BookController.php:26-37` (`#[IsGranted('ROLE_USER')]`, `assert($user instanceof User)`, repozytoria jako argumenty akcji).

### Model danych (atrybuty ORM, naming `underscore`)

- `src/Entity/ReadBook.php`: unikat `UNIQ_READ_BOOK_USER_BOOK(user_id, book_id)` (linia 14), indeks `IDX_READ_BOOK_BOOK(book_id)` (linia 15), `rating` smallint (linia 32), `readOn` jako data (linia 35; pole nazywa się `readOn`, nie `readAt`). Brak indeksu złożonego `(user_id, read_on)`.
- `src/Entity/Book.php`: `category` to `enumType: Category::class` zapisany jako VARCHAR(255) bez indeksu; `addedBy` → `User`; autorzy i tagi przez ManyToMany. **Brak pola soft-delete** (S-08 jeszcze nie istnieje); `BookRepository.php:94-97,114` wskazuje, że filtr S-08 ma trafić do `findAllForList`/`findForDetail` — zapytanie rekomendacji powinno przechodzić przez jedno miejsce, które S-08 później rozszerzy.
- `src/Enum/Category.php`: 11 wartości (Novel, Crime, Fantasy, NonFiction, Biography, History, Science, SelfHelp, Children, Poetry, Other).

### Repozytoria i serwisy

- `ReadBookRepository::findByUserIndexedByBookId` (linie 29-46) zwraca przeczytane przez użytkownika indeksowane po `book_id` — użyteczne do wykluczenia przeczytanych. `findCommentsForBook` (55-72) używa projekcji DQL `NEW App\Dto\CommentView(...)` — precedens dla lekkich DTO w `src/Dto`.
- Warstwa serwisowa: `src/Service/` (finalne klasy, wstrzykiwanie przez konstruktor, np. `ReadingManager.php:17-27`); brak interfejsów/tagów. `RecommendationService` pasuje do stylu. `MarkRead::today()` (`src/Dto/MarkRead.php:30`) to pomocnik „dziś” w Europe/Warsaw; `Symfony\Component\Clock\Clock` jest używany w `ReadBook`.
- Brak jakiegokolwiek natywnego SQL w projekcie (sprawdzone przez agenta pomocniczego).

### Reguła produktowa (PRD)

- `prd.md:105-113`: (1) książki o najwyższej średniej ocen wszystkich użytkowników, bez minimalnej liczby ocen; (2) z najczęściej czytanej przez użytkownika kategorii z ostatniego roku, jeszcze nieprzeczytane; (3) jeśli <3, powtórka dla drugiej kategorii; do 3 wyników; przy zerze komunikat.
- NFR: strona < 2 s (`prd.md:100`). Qps i wolumen danych nieustalone (`roadmap.md`, Open Questions 7-8) — przy 2-4 domownikach (roadmap, Vision recap) zapytanie bez cache jest wystarczające z dużym zapasem *(wniosek, nie pomiar)*.

### Testy, narzędzia, baza

- PHPUnit (`phpunit.dist.xml`), `dama/doctrine-test-bundle` (rollback transakcji), MariaDB 10.11 w testach (`.env.test`, port 3307), brak fixtur/fabryk — dane budowane inline (`tests/Repository/ReadBookRepositoryTest.php:20-50`). CI uruchamia `php bin/phpunit` (`.github/workflows/deploy.yml:53`). Nie znaleziono konfiguracji phpstan/cs-fixer w katalogu głównym (nie sprawdzono `composer.json` scripts/require-dev w całości).
- Cache: domyślny `cache.app` (filesystem); pule wyników Doctrine tylko w `when@prod` (`doctrine.yaml`). Cache per użytkownik groziłby nieaktualnością tuż po oznaczeniu książki jako przeczytanej; przy tej skali niepotrzebny.

### Biblioteki zewnętrzne (Exa, 2026-11-12)

| Pakiet | Co robi | Dopasowanie do reguły PRD |
| --- | --- | --- |
| `ankane/disco` v0.3.0 (2026-04-17), PHP >=8.2, MIT | CF przez faktoryzację macierzy; wymaga `ankane/libmf` i pobrania biblioteki współdzielonej w skryptach composera | Inny problem (podobieństwo gustów — Non-Goal); dodatkowa natywna zależność w kontenerze fly |
| `quellabs/recommender` 1.1.0 (2026-10-01), PHP >=8.2 | Item-CF + Slope One na warstwie DB CakePHP, MySQL-specific SQL | Inny problem; druga warstwa DB obok Doctrine; własne tabele |
| `phpjuice/slopeone` | Weighted Slope One w pamięci, PHP >=7.4 | Predykcja ocen; nie realizuje filtra kategorii i okna rocznego |
| `rustem-kaimolla/recommender-php` v1.0.0 | Item-item Jaccard, 1 instalacja na Packagist | Niedojrzały; inny problem |
| `andres-montanez/RecommendationsBundle`, `sh4ka/RecommenderBundle`, `kasperg/...sfPropel...` | Bundle Symfony (MongoDB / stary AppKernel / symfony 1) | Przestarzałe względem Symfony 8 *(wniosek z opisu READMe; nie sprawdzono dat commitów)* |

- Żaden znaleziony pakiet nie implementuje „najwyższa średnia w najczęściej czytanych kategoriach użytkownika, nieprzeczytane”. Zakres negatywny: jedno przeszukanie Exa (3 zapytania), nie przegląd całego Packagist.
- Sensowne uzupełnienie reguły bez biblioteki: średnia bayesowska `(n·avg + m·global) / (n + m)` ogranicza efekt książek z jedną oceną (np. Algolia, SQLAuthority). **To zmieniłoby regułę PRD** („bez minimalnej liczby ocen”), więc jest decyzją produktową, nie implementacyjną.

### Dokumentacja Symfony (Context7, 2026-11-12)

- Projekt używa `symfony/framework-bundle` `8.1.*` (`composer.json:23`). Context7 nie ma gałęzi docs 8.1; użyto najbliższej, `/symfony/symfony-docs/__branch__8.0`. Kod źródłowy ma tag `v8.1.9` (`/symfony/symfony`), a Console także `v8.1.9` (`/symfony/console`).
- Zapytanie dotyczyło tylko kontrolerów i routingu (nie Doctrine/DQL ani Twig), więc nie potwierdza nic o zapytaniu rekomendacji.
- Trasy: atrybut `#[Route('/blog', name: 'blog_list')]` z `Symfony\Component\Routing\Attribute\Route`; nazwy tras muszą być unikalne; query string nie wpływa na dopasowanie.
- Kontrolery: mogą rozszerzać `AbstractController` albo być zwykłą klasą z `#[Route]` (rejestrowana jako publiczny serwis z wstrzykiwaniem argumentów akcji). Kontroler inwokowalny: `#[Route]` na klasie plus `__invoke()`.
- Wniosek dla planu: istniejący `HomeController` (`#[Route('/', name: 'home')]`) jest zgodny z tym wzorcem; wstrzyknięcie `RecommendationService` jako argumentu akcji pasuje do dokumentacji.

## Code References

- `src/Controller/HomeController.php:13-17` - trasa `/`, brak zmiennych szablonu
- `templates/home/index.html.twig:9-11` - gałąź `app.user`
- `src/Entity/ReadBook.php:14-15,32,35` - unikat, indeks, `rating`, `readOn`
- `src/Entity/Book.php` (pole `category`, brak soft-delete)
- `src/Repository/ReadBookRepository.php:29-46,55-72` - przeczytane użytkownika; projekcja DTO
- `src/Repository/BookRepository.php:94-97,114` - miejsce na filtr soft-delete (S-08)
- `src/Service/ReadingManager.php:17-27` - wzorzec serwisu
- `context/foundation/prd.md:95,100,105-113` - FR-010, NFR, Business Logic

## Architecture Insights

- Konwencje: finalne klasy, `strict_types`, serwisy z wstrzykiwaniem konstruktora, DTO w `src/Dto`, DQL/QueryBuilder (bez SQL natywnego), migracje przez `make:migration`.
- Rekomendacja to czysty odczyt: żadnej nowej encji ani migracji nie wymaga reguła sama w sobie; ewentualny indeks `(user_id, read_on)` byłby optymalizacją, nie koniecznością przy tej skali *(wniosek)*.

## Historical Context (from prior changes)

- `context/foundation/roadmap.md` (S-04): zależy od S-03 (done); ryzyko — spójne kategorie z S-02 (stała lista `App\Enum\Category`, rozstrzygnięte) i data `readOn` zapisywana od początku (S-03, zrealizowane w `ReadBook`).
- `context/foundation/lessons.md`: wpisy (porównania po tekście komunikatów, trait vs serwis, collation) — dotyczą tu głównie „serwis zamiast trait”; kategoria jest enumem, więc lekcja o collation nie dotyczy.
- Brak wcześniejszych `research.md` w `context/changes/**` i `context/archive/**` (przejrzano listę plików z glob `context/**/*`).

## Related Research

Nie dotyczy.

## Open Questions

1. **Rozstrzyganie remisów**: równe średnie lub równa liczba przeczytanych w kategorii (PRD milczy) — decyzja do planu.
2. **Książki bez ocen**: czy kwalifikują się (średnia nieokreślona)? PRD: „bez minimalnej liczby ocen” sugeruje wymóg ≥1 oceny *(wniosek)*.
3. **Wykluczanie książek dodanych przez użytkownika, ale nieprzeczytanych** — PRD nie rozstrzyga.
4. **Czy ocena samego użytkownika wchodzi do średniej** — dotyczy tylko przeczytanych, które i tak są wykluczone; praktycznie nieistotne.
5. **Bayesowska średnia vs czysta średnia** — decyzja produktowa (zmienia FR-010).
6. **Interakcja z S-08** (soft-delete): zapytanie musi pomijać usunięte książki; kolejność wdrożenia S-04 i S-08.
7. **Luki**: nie czytano `translations/*`, `base.html.twig`, pełnych `composer.json` scripts/require-dev; qps/wolumen danych nadal nieznane.
