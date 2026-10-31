---
project: "My home library"
context_type: greenfield
created: 2026-10-16
updated: 2026-10-16
product_type: web-app
target_scale:
  users: small
timeline_budget:
  mvp_weeks: 5
  hard_deadline: 2027-01-10
  after_hours_only: true
checkpoint:
  current_phase: 8
  phases_completed: [1, 2, 3, 4, 5, 6, 7]
  gray_areas_resolved:
    - topic: "login method"
      decision: "e-mail + hasło, osobne konto na domownika"
    - topic: "account creation"
      decision: "właściciel zakłada konta ręcznie; brak rejestracji w MVP"
    - topic: "unauthenticated visibility"
      decision: "niezalogowany przegląda i wyszukuje książki (tytuł, autor, kategoria); oceny, notatki, rekomendacje wymagają logowania"
    - topic: "password reset"
      decision: "nice-to-have"
    - topic: "edit/delete rights for shared books"
      decision: "tylko autor wpisu książki; oceny i notatki osobiste"
    - topic: "persona scope"
      decision: "domownicy, jedna wspólna biblioteka, osobne konta i oceny"
    - topic: "problem category"
      decision: "tarcie w workflow, paraliż decyzyjny, brakująca funkcja, dane uwięzione w głowie"
  frs_drafted: 10
  quality_check_status: accepted
---

# Shape Notes

## Seed idea (verbatim)

Aplikacja do zarządzania domową biblioteką. Użytkownicy mają indywidualne konta i mogą dodawać, edytować i usuwać książki, które są wspólne dla wszystkich. Po zalogowaniu użytkownik oznacza książkę jako przeczytaną, ocenia ją w skali 1–5 i dodaje notatkę. Rekomendacja następnej lektury to średnia ocen wszystkich użytkowników, z wyższą wagą dla ocen w kategoriach, które ten użytkownik sam ocenia wysoko. Kontekst: PHP, 7–8 godzin tygodniowo, zgłoszenie 10 stycznia 2027.

Do rozstrzygnięcia: uprawnienia do edycji i usuwania wspólnych książek, źródło kategorii, zimny start, wzór wagi.

Opcjonalnie, nie jako wymaganie: notatki AI jako dodatkowy sygnał rekomendacji.

## Vision & Problem Statement

**Pain:** Użytkownik nie pamięta, jakie książki ma w domowej bibliotece i które z nich przeczytał.
**Moment:** Planowanie zakupu nowej książki.
**Cost today:** Użytkownik ręcznie sprawdza, czy ma już daną książkę. Grozi mu kupienie drugiego egzemplarza.
**Problem category:** Tarcie w workflow, paraliż decyzyjny, brakująca funkcja, dane uwięzione w głowie.
**Insight:** Ewidencja zbioru (co mam, co przeczytałem) jest fundamentem, a rekomendacja kolejnej lektury jest regułą domenową, która odróżnia produkt od pustego CRUD. Ewidencja zasila rekomendację danymi.

## User & Persona

**Primary persona:** Domownik prowadzący wspólną domową bibliotekę razem z innymi domownikami. Każdy ma własne konto, własne oceny i własny status przeczytania. Zbiór książek jest wspólny.
**Moment:** Planuje zakup nowej książki albo szuka, co przeczytać dalej.

## Access Control

**Logowanie:** e-mail + hasło. Każdy domownik ma własne konto. Konta zakłada właściciel ręcznie; w MVP nie ma samodzielnej rejestracji.
**Niezalogowany użytkownik:** Może przeglądać i wyszukiwać książki (tytuł, autor, kategoria). Nie widzi ocen, notatek, statusu przeczytania ani rekomendacji. Logowanie jest wymagane do dodawania, edycji i usuwania książek oraz do oznaczania jako przeczytana i oceniania.
**Role:** Brak ról administracyjnych w aplikacji. Wszyscy zalogowani widzą wszystkie książki i mogą dodawać nowe.
**Edycja i usuwanie książki:** Tylko użytkownik, który dodał daną książkę (autor wpisu).
**Oceny, status przeczytania i notatki:** Osobiste dla każdego użytkownika.
**Do rozstrzygnięcia:** Jak właściciel zakłada konta (poza aplikacją czy przez ekran administracyjny) i kto nim jest. Co dzieje się z ocenami i notatkami innych użytkowników po usunięciu książki przez jej autora. Co dzieje się z książkami, gdy konto autora zostanie usunięte.

## Success Criteria

**Pierwszy przepływ (MVP):**
1. Użytkownik loguje się.
2. Dodaje jedną lub kilka książek do wspólnego zbioru. Przed dodaniem system porównuje tytuł i autora z istniejącymi książkami i, jeśli znajdzie duplikat, wyświetla informację przed zapisaniem.
3. Oznacza wybrane książki jako przeczytane.
4. Ocenia je w skali 1–5 (notatka jest nice-to-have, patrz FR-009).
5. Na stronie głównej panelu widzi rekomendacje kolejnych książek do przeczytania, oparte na książkach, które już przeczytał.

### Primary
- Zalogowany użytkownik przechodzi cały przepływ powyżej (kroki 1–5) i widzi rekomendacje kolejnych książek do przeczytania.

### Secondary
- Notatki AI dodane pod książkami jako dodatkowy sygnał rekomendacji.
- Uzasadnienie rekomendacji przy każdej pozycji.

### Guardrails
- Oceny i notatki są osobiste. Użytkownik nie może edytować ani usuwać cudzych ocen i notatek.
- Zapisane książki, oceny i notatki nie giną po ponownym zalogowaniu ani po edycji.
- Konta są bezpieczne. Hasła nie są przechowywane jawnie, a dane osobiste (oceny, notatki, status przeczytania, rekomendacje) bez logowania są niedostępne.

## Timeline acknowledgment

Acknowledged on 2026-10-16: 5-week MVP requires sustained dedication; user accepted. (Zgłoszone przez użytkownika: potrzeba 4–5 tygodni przy 7–8 godzinach tygodniowo po godzinach.)

## Functional Requirements

### Konta i dostęp

- FR-001: Użytkownik może zalogować się do systemu. Priority: must-have
  > Socrates: Counter-argument considered: "przeglądanie i wyszukiwanie książek jest możliwe bez logowania; logowanie jest potrzebne tylko do dodawania, edycji i usuwania książek oraz do oznaczania jako przeczytana i oceniania." Resolution: kept; ten podział zapisano w FR-003 i w Access Control.
- FR-002: Użytkownik, który zapomniał hasła, może je zresetować. Priority: nice-to-have
  > Socrates: Counter-argument considered: "reset hasła wymaga wysyłania e-maili." Resolution: obniżone z must-have do nice-to-have; w MVP wystarczy ręczna zmiana hasła przez właściciela.

Rejestracja (samodzielne zakładanie konta) wypadła z MVP: konta zakłada właściciel ręcznie.
> Socrates: Counter-argument considered: "przy 2–4 domownikach konta można założyć ręcznie." Resolution: FR rejestracji usunięty z MVP.

### Książki

- FR-003: Każdy użytkownik, także niezalogowany, może przeglądać i wyszukiwać książki (tytuł, autor, kategoria). Priority: must-have
- FR-004: Zalogowany użytkownik może dodać książkę do wspólnego zbioru, wpisując tytuł, autora lub autorów, numer ISBN i rok, krótką notatkę, wybierając kategorię oraz dodając do około pięciu tagów. Tytuł i autor są polami wymaganymi i podlegają walidacji. Priority: must-have
  > Socrates: Counter-argument considered: "każdy może dodać wpis o błędnym tytule lub autorze." Resolution: kept; pola tytuł i autor wymagane i walidowane, a przy porównywaniu duplikatów ignorowane są wielkość liter i zbędne spacje.
- FR-005: System wyświetla informację przed dodaniem książki, jeśli jest ona duplikatem. Porównanie opiera się na tytule i autorze (z pominięciem wielkości liter i zbędnych spacji) oraz na kodzie ISBN wpisanym przez użytkownika przy dodawaniu. Decyzja o dodaniu należy do użytkownika dodającego książkę. Priority: must-have
  > Socrates: Counter-argument considered: "różne wydania tej samej książki to prawdziwe duplikaty lub nie." Resolution: kept; użytkownik wpisuje przy dodawaniu kod ISBN, na podstawie którego również wykonywane jest porównanie.
- FR-006: Autor wpisu książki może wprowadzić w niej zmiany po wcześniejszym potwierdzeniu. Priority: must-have
  > Socrates: Counter-argument considered: "tylko autor może poprawić literówkę we wspólnym wpisie." Resolution: kept; opcje awaryjne (poprawa cudzego wpisu) wrócą później.
- FR-007: Autor wpisu książki może ją usunąć. Usunięta książka znika ze wszystkich list, a oceny i notatki innych użytkowników pozostają zachowane (usunięcie nie kasuje danych). Priority: must-have
  > Socrates: Counter-argument considered: "usunięcie książki kasuje cudze oceny i notatki." Resolution: usunięcie oznacza książkę jako usuniętą (soft delete): nie jest widoczna na żadnej liście, a dane pozostają w bazie.

### Czytanie i oceny

- FR-008: Zalogowany użytkownik może w ramach swojego konta oznaczyć książkę jako przeczytaną i wystawić ocenę (skala 1–5). Priority: must-have
  > Socrates: Counter-argument considered: "notatka to osobny koszt, a rekomendacja z niej nie korzysta." Resolution: notatka wydzielona do FR-009 jako nice-to-have; FR-008 obejmuje tylko status przeczytania i ocenę.
- FR-009: Zalogowany użytkownik może w ramach swojego konta dodać do przeczytanej książki notatkę. Priority: nice-to-have

### Rekomendacje

- FR-010: Strona główna pokazuje zalogowanemu użytkownikowi do trzech rekomendacji kolejnych książek, budowanych na podstawie ocen wszystkich użytkowników, którzy przeczytali daną książkę, i nastawionych na kategorie książek przeczytanych przez niego w ciągu ostatniego roku. Priority: must-have
  > Socrates: Counter-argument considered: "kilka kroków reguły (kategoria, inna kategoria, limit) to duży koszt pierwszego przepływu." Resolution: kept; użytkownik podał regułę w wersji z kolejnymi kategoriami (patrz Business Logic): książki o najwyższych ocenach, z kategorii najczęściej czytanej w ostatnim roku, jeszcze nieprzeczytane; jeśli nie ma trzech, powtórka dla drugiej najpopularniejszej kategorii.

## User Stories

### US-01: Użytkownik dodaje książki, ocenia przeczytaną i widzi rekomendacje

- **Given** użytkownik zalogował się do panelu
- **When** dodaje książki, oznacza wybraną książkę jako przeczytaną i dodaje jej ocenę, a następnie przechodzi na stronę główną
- **Then** widzi rekomendacje, jaką kolejną książkę może przeczytać

#### Acceptance Criteria
- Przed dodaniem książki, która jest duplikatem (tytuł, autor, ISBN), system wyświetla informację o duplikacie, a decyzja o dodaniu należy do użytkownika (FR-005).
- Na stronie głównej widać nie więcej niż trzy rekomendacje; jeśli system nie jest w stanie wygenerować trzech, wyświetla tyle, ile wygenerował (FR-010).

## Business Logic

Aplikacja rekomenduje użytkownikowi do trzech nieprzeczytanych przez niego książek o najwyższych ocenach, wybranych z kategorii, które czytał najczęściej w ostatnim roku, a gdy nie uzbiera trzech, powtarza wybór dla jego drugiej najczęściej czytanej kategorii.

**Dane wejściowe:** Oceny wystawione przez wszystkich użytkowników (średnia ocen książki, bez minimalnej liczby ocen). Książki, które dany użytkownik oznaczył jako przeczytane. Data oznaczenia książki jako przeczytanej, która określa, co liczy się jako „ostatni rok”. Kategoria każdej książki.

**Kroki:** (1) Z książek wybierane są te o najwyższych średnich ocenach. (2) Z tej listy brane są książki z kategorii najczęściej czytanej przez użytkownika w ostatnim roku, których użytkownik jeszcze nie przeczytał. (3) Jeśli nie da się wybrać trzech, krok 2 jest powtarzany dla drugiej najczęściej czytanej kategorii. Jeśli użytkownik czytał tylko jedną kategorię, wyświetla się tyle rekomendacji, ile system zdołał wygenerować.

**Wynik:** Do trzech rekomendowanych książek na stronie głównej. Gdy system nie wygeneruje żadnej, użytkownik widzi komunikat wyjaśniający, np. „Przeczytaj i oceń kilka książek, aby dostać rekomendacje”.

## Non-Functional Requirements

- Strona główna z rekomendacjami jest widoczna dla zalogowanego użytkownika w czasie krótszym niż 2 sekundy od wejścia na nią.
- Oceny, notatki i status przeczytania nie są widoczne dla osób niezalogowanych.
- Zapisane książki, oceny i notatki są dostępne po ponownym zalogowaniu i po restarcie aplikacji.
- Aplikacja jest używalna w dwóch ostatnich głównych wersjach czterech popularnych przeglądarek desktopowych.

## Product Framing

**Typ produktu:** Strona internetowa lub aplikacja webowa.
**Skala:** Mała, użytkownik lub garstka osób (domownicy).
**Czas:** Termin 10 stycznia 2027, praca po godzinach. 100x skali nie zmienia reguły rekomendacji; większa liczba ocen stabilizuje średnie.

## Non-Goals

- **Samodzielna rejestracja kont:** konta zakłada właściciel ręcznie, bo użytkowników jest 2–4.
- **Pobieranie danych książki z zewnętrznego serwisu po ISBN:** dane wpisuje użytkownik ręcznie, bez integracji zewnętrznych.
- **Rekomendacje oparte na podobieństwie gustu między użytkownikami:** zostaje reguła ze średnią ocen i kategorią.
- **Wiele oddzielnych bibliotek lub gospodarstw domowych:** jeden wspólny zbiór dla wszystkich kont.
- **Import wielu książek naraz (np. z pliku):** książki dodawane są pojedynczo przez formularz.

## Open Questions (running)

1. **Czym jest „rok” w polach książki** — rok dodania do zbioru czy rok wydania? — Owner: użytkownik.
2. **Czy kategoria pochodzi ze stałej listy, czy jest wolnym tekstem, i jakie są kategorie?** Wybór wpływa na zliczanie „najczęściej czytanej” kategorii. — Owner: użytkownik. Block: tak (reguła rekomendacji zależy od spójnych kategorii).
3. **Do czego służą tagi i notatka książki?** Reguła rekomendacji ich nie używa; są w FR-004 jako część wpisu. Czy to must-have w MVP? — Owner: użytkownik.
4. **Jak właściciel zakłada konta i kto nim jest** (poza aplikacją czy ekran administracyjny). — Owner: użytkownik.
5. **Co dzieje się z książkami, gdy konto ich autora zostanie usunięte.** — Owner: użytkownik.
6. **Czy ISBN jest polem wymaganym i jak łączą się dopasowania po ISBN oraz po tytule i autorze** przy ostrzeżeniu o duplikacie. — Owner: użytkownik.

## Forward: tech-stack

- Preferowana rodzina języków: PHP (zgłoszone przez użytkownika, 8 lat doświadczenia).
