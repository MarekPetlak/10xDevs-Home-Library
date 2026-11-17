# Edit own book entry Implementation Plan

## Overview

Właściciel wpisu (`Book::addedBy`) może zmienić wszystkie pola FR-004 przez `/books/{id}/edit`. Każdy inny zalogowany użytkownik dostaje 403, anonimowy trafia na logowanie. Edycja nie dotyka ocen ani komentarzy (FR-006, roadmapa S-07).

## Current State Analysis

- Dodawanie: `BookController::new` (`src/Controller/BookController.php:68`) używa `BookType` z DTO `NewBook`, które jest jedynym właścicielem reguł walidacji. Po walidacji `DuplicateFinder::find` ostrzega, a przycisk `confirm_duplicate` jest decyzją użytkownika (FR-005).
- `BookManager::add` (`src/Service/BookManager.php:35`) robi find-or-create autorów i tagów w transakcji. `UniqueConstraintViolationException` zamienia na `BookConflictException`, bo Doctrine zamyka wtedy EntityManager.
- `Book` ma settery dla wszystkich pól FR-004 oraz `addAuthor`/`removeAuthor`/`addTag`/`removeTag`. Oceny i komentarze żyją w osobnej encji `ReadBook`, więc edycja pól książki ich nie rusza.
- `DuplicateFinder::find` (`src/Service/DuplicateFinder.php:28`) nie wyklucza żadnej książki, więc przy edycji znalazłby edytowaną książkę po jej własnym ISBN.
- W repozytorium nie ma jeszcze żadnego votera ani `access_control`. Trasy chroni `#[IsGranted('ROLE_USER')]`.
- `PrivacyMatrixTest` jest centralnym miejscem reguł prywatności i własności.

## Desired End State

- Na stronie szczegółów właściciel widzi link „Edytuj”, inni użytkownicy i anonimowi go nie widzą.
- `GET/POST /books/{id}/edit` pokazuje formularz wypełniony aktualnymi wartościami, z tą samą walidacją co przy dodawaniu. Przycisk „Zapisz” zapisuje zmiany, a po sukcesie następuje przekierowanie na stronę szczegółów z flashem.
- Jeśli nowe wartości dublują inną książkę, pojawia się ostrzeżenie z przyciskiem „zapisz mimo to”. Edytowana książka nie liczy się jako duplikat samej siebie.
- Oceny i komentarze innych użytkowników po edycji są nienaruszone.
- Weryfikacja: `vendor/bin/phpunit` i `vendor/bin/phpstan analyse` przechodzą.

### Key Discoveries:

- `src/Dto/NewBook.php`: reguły walidacji w jednym miejscu, formularz edycji używa tego samego DTO bez zmiany nazwy.
- `src/Controller/BookController.php:68`: wzorzec dwukrokowy z `confirm_duplicate` i odpowiedzią 400 dla nieudanego/ostrzegającego submitu, do powtórzenia w `edit`.
- `src/Service/BookManager.php:35`: wzorzec `try/catch` na `UniqueConstraintViolationException` z transakcją, do powtórzenia w `update`.
- `templates/book/new.html.twig`: ukrywanie `confirm_duplicate` przez `setRendered`, gdy nie ma ostrzeżenia.

## What We're NOT Doing

- Osobnego kroku podsumowania zmian ani okna `confirm()`. Potwierdzeniem jest przycisk „Zapisz” na dedykowanym formularzu edycji (decyzja użytkownika).
- Usuwania książek (S-08), poprawiania cudzych wpisów ani roli administratora.
- Historii zmian (kto i co zmienił); zapisujemy tylko moment ostatniej edycji.
- Sprzątania osieroconych autorów i tagów po edycji.
- Zmiany reguł walidacji ani zmiany nazwy `NewBook`/`BookType`.

## Implementation Approach

Najpierw reguła dostępu i logika domenowa (voter, `BookManager::update`, wykluczenie w `DuplicateFinder`), potem trasa i widok, na końcu testy. Edycja reużywa `BookType` i `NewBook`. Formularz jest wypełniany z encji, a zapis zastępuje zestawy autorów i tagów w jednej transakcji.

## Phase 1: Access rule and domain logic

### Overview

Dostarcza regułę „tylko właściciel” oraz serwisową logikę edycji, bez UI.

### Changes Required:

#### 1. Ownership voter

**File**: `src/Security/BookVoter.php` (nowy)

**Intent**: Jedno miejsce reguły, kto może edytować książkę, żeby kontroler i szablon pytały to samo.

**Contract**: atrybut `BookVoter::EDIT = 'BOOK_EDIT'`, subject `App\Entity\Book`. Zwraca true tylko dla zalogowanego `App\Entity\User` równego `$book->getAddedBy()` (porównanie po id). Autokonfiguracja Symfony wystarcza, bez wpisu w `services.yaml`.

#### 2. Prefill DTO from entity

**File**: `src/Dto/NewBook.php`

**Intent**: Umożliwić wypełnienie formularza edycji aktualnymi wartościami książki bez duplikowania logiki formatowania list.

**Contract**: statyczna `NewBook::fromBook(Book $book): self` kopiuje pola. `authors` to pełne imiona rozdzielone `, `, `tags` to nazwy rozdzielone `, `. Pola i reguły walidacji bez zmian.

#### 3. Update service

**File**: `src/Service/BookManager.php`

**Intent**: Zapisać zmiany istniejącej książki z już zwalidowanych danych, zostawiając `addedBy` i `createdAt` bez zmian i ustawiając `updatedAt`.

**Contract**: `update(Book $book, NewBook $input): Book`, `@throws BookConflictException`. Ten sam schemat co `add`: transakcja, `UniqueConstraintViolationException` jest zamieniany na `BookConflictException`. Zastępuje autorów i tagi (usuwa te spoza listy, dodaje nowe przez istniejące `findOrCreateAuthor`/`findOrCreateTag`). Przy każdym udanym zapisie ustawia `updatedAt` na bieżący czas (zegar Symfony `now()`, żeby testy z `MockClock` były deterministyczne). Wyodrębnić wspólne przypisywanie pól z `save`, żeby `add` i `update` nie duplikowały kodu (bez traita, zgodnie z lekcją o serwisach).

#### 3a. updatedAt column and migration

**Files**: `src/Entity/Book.php`, `migrations/Version<timestamp>.php` (nowy, generowany przez `doctrine:migrations:diff`)

**Intent**: Zapamiętać, kiedy wpis ostatnio edytowano, żeby było to widać na stronie książki.

**Contract**: `Book::$updatedAt` typu `?\DateTimeImmutable`, kolumna `updated_at` nullable (`null` = wpis nigdy nie edytowano, bez backfillu istniejących wierszy), `getUpdatedAt()` i `setUpdatedAt(\DateTimeImmutable)`. Konstruktor go nie ustawia. Migracja dodaje tylko tę kolumnę; zgodnie z lekcją o migracjach w komentarzu podaje, że nullable `ADD COLUMN` na MariaDB 10.11 (produkcja i CI) jest operacją natychmiastową, bez przebudowy tabeli i długiej blokady. `doctrine:schema:validate` nie może zgłaszać różnic.

#### 4. Exclude edited book from duplicate check

**Files**: `src/Service/DuplicateFinder.php`, `src/Repository/BookRepository.php`

**Intent**: Edytowana książka nie może ostrzegać sama przed sobą.

**Contract**: `DuplicateFinder::find(NewBook $input, ?int $excludeBookId = null)` i `BookRepository::findDuplicates(..., int $limit = 3, ?int $excludeBookId = null)` dodaje `b.id != :excludeId`, gdy podano id. Domyślne zachowanie (`null`) bez zmian, więc istniejące testy dodawania nadal przechodzą.

### Success Criteria:

#### Automated Verification:

- Istniejące testy dodawania i duplikatów przechodzą: `vendor/bin/phpunit tests/Controller/BookControllerTest.php`
- Analiza statyczna przechodzi: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- Testy jednostkowe/serwisowe dla `BookVoter`, `BookManager::update` i `DuplicateFinder` z wykluczeniem przechodzą: `vendor/bin/phpunit tests/Service tests/Security`

**Implementation Note**: Po zakończeniu fazy i przejściu testów automatycznych poczekaj na potwierdzenie człowieka przed fazą 2.

---

## Phase 2: Edit route and UI

### Overview

Udostępnia edycję użytkownikowi: trasa, szablon, link dla właściciela, komunikaty.

### Changes Required:

#### 1. Edit action

**File**: `src/Controller/BookController.php`

**Intent**: Obsłużyć wyświetlenie i zapis formularza edycji z regułą własności i ostrzeżeniem o duplikacie.

**Contract**: `#[Route('/books/{id}/edit', name: 'book_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]` z `#[IsGranted('ROLE_USER')]`. Nieistniejąca książka daje 404. Brak `BOOK_EDIT` daje 403 (`denyAccessUnlessGranted`). Formularz z `BookType` i `NewBook::fromBook`. Przepływ jak w `new`: submit niepoprawny albo z ostrzeżeniem daje 400 i ponowne renderowanie, a `confirm_duplicate` pomija ostrzeżenie. Wywołanie `DuplicateFinder::find($data, $book->getId())`. `BookConflictException` daje błąd formularza `book.error.conflict`. Sukces: flash `book.flash.updated` i przekierowanie na `book_show`.

#### 2. Edit template

**File**: `templates/book/edit.html.twig` (nowy)

**Intent**: Ten sam układ co `new.html.twig`, z nagłówkiem edycji i linkiem powrotnym do szczegółów.

**Contract**: reużywa `_duplicate_warning.html.twig` i `setRendered` dla `confirm_duplicate`. Etykieta przycisku „Zapisz” pochodzi z istniejącego `book.form.submit` albo nowego klucza dla edycji, jeśli „Dodaj” jest tam zakodowane (sprawdzić tłumaczenie).

#### 3. Owner-only link

**File**: `templates/book/show.html.twig`

**Intent**: Pokazać link „Edytuj” tylko właścicielowi.

**Contract**: warunek `{% if is_granted('BOOK_EDIT', book) %}` otacza link do trasy `book_edit`.

#### 3a. Last-edited info on detail page

**File**: `templates/book/show.html.twig`

**Intent**: Pokazać czytelnikom, że wpis był poprawiany, i kiedy.

**Contract**: wiersz `<dt>/<dd>` „Zaktualizowano” z `book.updatedAt|date('d.m.Y')` w liście `<dl>` książki, renderowany tylko gdy `book.updatedAt` nie jest `null`.

#### 4. Translations

**File**: `translations/messages.pl.yaml`

**Intent**: Teksty po polsku dla nagłówka edycji, linku, flasha sukcesu i ewentualnej etykiety przycisku.

**Contract**: klucze `book.edit.title`, `book.edit.heading`, `book.show.edit`, `book.show.updated`, `book.flash.updated` (oraz etykieta przycisku, jeśli potrzebna), w stylu istniejących kluczy `book.*`.

### Success Criteria:

#### Automated Verification:

- Testy przechodzą: `vendor/bin/phpunit`
- Analiza statyczna przechodzi: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- Kontener i trasy poprawne: `php bin/console lint:container` i `php bin/console debug:router book_edit`
- Szablony poprawne: `php bin/console lint:twig templates`

#### Manual Verification:

- Właściciel widzi „Edytuj” na własnej książce, formularz jest wypełniony, zapis zmienia dane i pokazuje flash.
- Na cudzej książce nie ma linku, a wejście na `/books/{id}/edit` daje 403.
- Zmiana ISBN na istniejący w innej książce pokazuje ostrzeżenie z „zapisz mimo to”.
- Oceny i komentarze innych użytkowników nadal widoczne po edycji.

**Implementation Note**: Po zakończeniu fazy i przejściu testów automatycznych poczekaj na potwierdzenie człowieka przed fazą 3.

---

## Phase 3: Tests

### Overview

Zamyka ryzyko z roadmapy: tylko właściciel edytuje, edycja nie gubi ocen ani komentarzy.

### Changes Required:

#### 1. Controller tests

**File**: `tests/Controller/BookEditControllerTest.php` (nowy)

**Intent**: Pokryć przepływ edycji w stylu istniejących testów kontrolera.

**Contract**: przypadki: właściciel widzi wypełniony formularz; poprawny zapis zmienia wszystkie pola (w tym autorów i tagi) i przekierowuje z flashem; niepoprawne dane dają 400 bez zmian w bazie; zmiana na ISBN innej książki daje ostrzeżenie, a `confirm_duplicate` zapisuje; zapis bez zmiany ISBN nie ostrzega o samej sobie; brak lub zły token CSRF jest odrzucany; `ReadBook` (ocena i komentarz) innego użytkownika po edycji ma te same wartości; `updatedAt` jest `null` przed edycją i równy czasowi z `MockClock` po zapisie, a strona szczegółów pokazuje wiersz „Zaktualizowano” dopiero po edycji; nieistniejąca książka daje 404.

#### 2. Privacy matrix rows

**File**: `tests/Controller/PrivacyMatrixTest.php`

**Intent**: Wpisać regułę własności do centralnej macierzy.

**Contract**: anonimowy na `/books/{id}/edit` dostaje redirect na logowanie; zalogowany nie-właściciel dostaje 403 (GET i POST); link „Edytuj” widoczny tylko dla właściciela na stronie szczegółów.

#### 3. Service and voter tests

**Files**: `tests/Security/BookVoterTest.php`, `tests/Service/BookManagerUpdateTest.php`, test wykluczenia dla `DuplicateFinder` obok istniejących testów serwisów (sprawdzić lokalizację)

**Intent**: Przypiąć logikę fazy 1.

**Contract**: voter: właściciel true, inny użytkownik i anonimowy false. `update`: zastępuje autorów i tagi, zachowuje `addedBy` i `createdAt`, ustawia `updatedAt`. `find` z `excludeBookId` nie zwraca wykluczonej książki.

### Success Criteria:

#### Automated Verification:

- Wszystkie testy przechodzą: `vendor/bin/phpunit`
- Analiza statyczna przechodzi: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

#### Manual Verification:

- Przegląd, że wiersze w `PrivacyMatrixTest` jasno opisują regułę własności.

---

## Testing Strategy

### Unit Tests:

- `BookVoter`: właściciel, inny użytkownik, anonimowy.
- `BookManager::update`: zastąpienie autorów i tagów, zachowanie `addedBy`/`createdAt`.
- `DuplicateFinder` z `excludeBookId`.

### Integration Tests:

- `BookEditControllerTest` i wiersze `PrivacyMatrixTest` jak w fazie 3.

### Manual Testing Steps:

1. Zaloguj się jako autor wpisu, otwórz książkę, kliknij „Edytuj”, zmień tytuł i tagi, zapisz.
2. Zaloguj się jako inny użytkownik i wejdź na `/books/{id}/edit` tej książki (oczekiwane 403).
3. Zmień ISBN na istniejący w innej książce i sprawdź ostrzeżenie oraz „zapisz mimo to”.

## Performance Considerations

Bez wpływu: jedno zapytanie więcej na odczyt formularza, zapis w jednej transakcji, skala 2–3 qps.

## Migration Notes

Jedna migracja dodaje nullable kolumnę `book.updated_at` (bez backfillu: `null` oznacza „nigdy nie edytowano”). Wycofanie: usunięcie kolumny w `down()`.

## References

- Roadmap: `context/foundation/roadmap.md` (S-07), PRD: `context/foundation/prd.md` FR-006, FR-005, FR-004
- Similar implementation: `src/Controller/BookController.php:68`, `src/Service/BookManager.php:35`
- Lessons: `context/foundation/lessons.md` (serwis zamiast traita)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Access rule and domain logic

#### Automated

- [x] 1.1 Istniejące testy dodawania i duplikatów przechodzą: `vendor/bin/phpunit tests/Controller/BookControllerTest.php` — a36d9d2
- [x] 1.2 Analiza statyczna przechodzi: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — a36d9d2
- [x] 1.3 Testy jednostkowe/serwisowe dla `BookVoter`, `BookManager::update` i `DuplicateFinder` z wykluczeniem przechodzą: `vendor/bin/phpunit tests/Service tests/Security` — a36d9d2
- [x] 1.4 Migracja `updated_at` stosuje się, a schemat jest zgodny: `php bin/console doctrine:migrations:migrate --no-interaction` i `php bin/console doctrine:schema:validate` — a36d9d2

### Phase 2: Edit route and UI

#### Automated

- [x] 2.1 Testy przechodzą: `vendor/bin/phpunit` — 4746aec
- [x] 2.2 Analiza statyczna przechodzi: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — 4746aec
- [x] 2.3 Kontener i trasy poprawne: `php bin/console lint:container` i `php bin/console debug:router book_edit` — 4746aec
- [x] 2.4 Szablony poprawne: `php bin/console lint:twig templates` — 4746aec

#### Manual

- [x] 2.5 Właściciel widzi „Edytuj” na własnej książce, formularz jest wypełniony, zapis zmienia dane i pokazuje flash — 4746aec
- [x] 2.6 Na cudzej książce nie ma linku, a wejście na `/books/{id}/edit` daje 403 — 4746aec
- [x] 2.7 Zmiana ISBN na istniejący w innej książce pokazuje ostrzeżenie z „zapisz mimo to” — 4746aec
- [x] 2.8 Oceny i komentarze innych użytkowników nadal widoczne po edycji — 4746aec
- [x] 2.9 Po edycji strona książki pokazuje „Zaktualizowano” z dzisiejszą datą, a nieedytowana książka tego wiersza nie ma — 4746aec

### Phase 3: Tests

#### Automated

- [x] 3.1 Wszystkie testy przechodzą: `vendor/bin/phpunit` — c123c53
- [x] 3.2 Analiza statyczna przechodzi: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — c123c53

#### Manual

- [x] 3.3 Przegląd, że wiersze w `PrivacyMatrixTest` jasno opisują regułę własności — c123c53
