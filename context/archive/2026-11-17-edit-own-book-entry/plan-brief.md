# Edit own book entry — Plan Brief

> Full plan: `context/changes/edit-own-book-entry/plan.md`

## What & Why

Autor wpisu książki może poprawić jej dane (FR-006, roadmapa S-07), np. literówkę w tytule lub złe ISBN. Nikt inny nie może edytować cudzego wpisu, a edycja nie może gubić ocen ani komentarzy innych użytkowników.

## Starting Point

Książki można dodawać (`BookController::new`, `BookType`, DTO `NewBook`, `BookManager::add`, ostrzeżenie o duplikacie). Edycji nie ma, nie ma też żadnego votera ani reguły własności. Oceny i komentarze siedzą w osobnej encji `ReadBook`.

## Desired End State

Właściciel widzi na stronie książki link „Edytuj” i zmienia wszystkie pola FR-004 na formularzu wypełnionym aktualnymi danymi. Inni zalogowani dostają 403, anonimowi trafiają na logowanie. Ostrzeżenie o duplikacie działa jak przy dodawaniu, ale pomija edytowaną książkę. Strona książki pokazuje „Zaktualizowano” z datą ostatniej edycji.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Potwierdzenie (FR-006) | Jeden przycisk „Zapisz” na dedykowanym formularzu | Wybór użytkownika; bez dodatkowego kroku | Plan |
| Dostęp nie-właściciela | 403 przez voter `BOOK_EDIT` | Książki są publiczne, nic nie wycieka; idiomatyczny Symfony | Plan |
| Duplikaty przy edycji | Ostrzeżenie z wykluczeniem edytowanej książki | Edycja nie może po cichu stworzyć kopii | Plan |
| Zakres pól | Wszystkie pola FR-004 | Poprawia też najczęstsze literówki (ISBN, autor) | Plan |
| Reużycie kodu | `BookType` i `NewBook` bez zmiany nazwy, `BookManager::update` | Jeden właściciel reguł walidacji | Plan |
| Testy | Kontroler + wiersze w `PrivacyMatrixTest` | Reguła własności w centralnej macierzy | Plan |

## Scope

**In scope:** voter własności, `BookManager::update`, kolumna `updatedAt` z migracją i wiersz „Zaktualizowano” na stronie książki, wykluczenie w `DuplicateFinder`, trasa `/books/{id}/edit`, szablon, link dla właściciela, tłumaczenia, testy.

**Out of scope:** usuwanie (S-08), edycja cudzych wpisów, admin, historia zmian (kto i co), sprzątanie osieroconych autorów i tagów, osobny krok podsumowania.

## Architecture / Approach

`BookController::edit` sprawdza `BOOK_EDIT`, buduje formularz z `NewBook::fromBook`, waliduje i ostrzega o duplikatach (z wykluczeniem własnego id). `BookManager::update` w jednej transakcji zastępuje autorów i tagi, ustawiając `updatedAt` i zostawiając `addedBy`, `createdAt` i `ReadBook` bez zmian. Wyścigi na unikalnych autorach i tagach obsługuje `BookConflictException` jak przy dodawaniu.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Access rule and domain logic | Voter, `update`, `updatedAt` z migracją, wykluczenie duplikatu | Zmiana sygnatur nie może zepsuć dodawania; migracja na żywej tabeli |
| 2. Edit route and UI | Trasa, szablon, link dla właściciela, flash | Etykieta przycisku „Zapisz” vs istniejące tłumaczenie „Dodaj” |
| 3. Tests | Testy kontrolera, serwisów, macierz prywatności | Pokrycie scenariusza „oceny przetrwały edycję” |

**Prerequisites:** S-02 (dodawanie książki) zrobione.
**Estimated effort:** ~2 sesje, 3 fazy.

## Open Risks & Assumptions

- Zmiana kategorii przez właściciela może wpłynąć na rekomendacje innych; przyjmujemy to jako jego korektę.
- `updatedAt` jest nullable bez backfillu (`null` = nigdy nie edytowano). To jedyna zmiana schematu: jedna migracja dodająca kolumnę.
- Przycisk „Zapisz” jest jedynym potwierdzeniem, co może być słabsze niż „wcześniejsze potwierdzenie” z PRD. Decyzja użytkownika.

## Success Criteria (Summary)

- Właściciel zmienia dane własnej książki, a zmiany są widoczne na stronie szczegółów.
- Nie-właściciel dostaje 403, anonimowy trafia na logowanie.
- Oceny i komentarze innych użytkowników pozostają po edycji, a `phpunit` i `phpstan` przechodzą.
