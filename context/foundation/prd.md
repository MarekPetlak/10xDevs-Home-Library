---
project: "My home library"
version: 2
status: draft
created: 2026-10-16
context_type: greenfield
product_type: web-app
target_scale:
  users: small
  qps: # TODO: qps — see Open Questions
  data_volume: # TODO: data_volume — see Open Questions
timeline_budget:
  mvp_weeks: 5
  hard_deadline: 2027-01-10
  after_hours_only: true
---

# My home library — PRD

> Wersja 2 (2026-11-03): FR-004 uściślony po rozstrzygnięciu Open Questions 1, 2, 3 i 6 (bez notatki książki, opcjonalny opis, autorzy i tagi jako encje, rok wydania, stała lista kategorii, ISBN wymagany).

## Vision & Problem Statement

Użytkownik nie pamięta, jakie książki ma w domowej bibliotece i które z nich przeczytał. Planując zakup nowej książki, musi ręcznie sprawdzać, czy nie ma jej już w swoim zbiorze, i grozi mu kupienie drugiego egzemplarza. Problem to tarcie w workflow, paraliż decyzyjny, brakująca funkcja i dane uwięzione w głowie.

Ewidencja zbioru (co mam, co przeczytałem) jest fundamentem, a rekomendacja kolejnej lektury jest regułą domenową, która odróżnia produkt od pustego CRUD. Ewidencja zasila rekomendację danymi.

## User & Persona

**Primary persona:** Domownik prowadzący wspólną domową bibliotekę razem z innymi domownikami. Każdy ma własne konto, własne oceny i własny status przeczytania. Zbiór książek jest wspólny.

**Moment:** Planuje zakup nowej książki albo szuka, co przeczytać dalej.

## Success Criteria

### Primary
- Zalogowany użytkownik przechodzi cały przepływ MVP i widzi rekomendacje kolejnych książek do przeczytania: loguje się; dodaje jedną lub kilka książek do wspólnego zbioru (przed dodaniem system informuje o duplikacie); oznacza wybrane książki jako przeczytane; ocenia je w skali 1–5; na stronie głównej panelu widzi rekomendacje oparte na książkach, które już przeczytał.

### Secondary
- Notatki AI dodane pod książkami jako dodatkowy sygnał rekomendacji.
- Uzasadnienie rekomendacji przy każdej pozycji.

### Guardrails
- Oceny i komentarze są osobiste. Użytkownik nie może edytować ani usuwać cudzych ocen i komentarzy.
- Zapisane książki, oceny i komentarze nie giną po ponownym zalogowaniu ani po edycji.
- Konta są bezpieczne. Hasła nie są przechowywane jawnie, a dane osobiste (oceny, komentarze, status przeczytania, rekomendacje) bez logowania są niedostępne.

## User Stories

### US-01: Użytkownik dodaje książki, ocenia przeczytaną i widzi rekomendacje

- **Given** użytkownik zalogował się do panelu
- **When** dodaje książki, oznacza wybraną książkę jako przeczytaną i dodaje jej ocenę, a następnie przechodzi na stronę główną
- **Then** widzi rekomendacje, jaką kolejną książkę może przeczytać

#### Acceptance Criteria
- Przed dodaniem książki, która jest duplikatem (tytuł, autor, ISBN), system wyświetla informację o duplikacie, a decyzja o dodaniu należy do użytkownika (FR-005).
- Na stronie głównej widać nie więcej niż trzy rekomendacje; jeśli system nie jest w stanie wygenerować trzech, wyświetla tyle, ile wygenerował (FR-010).

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
- FR-004: Zalogowany użytkownik może dodać książkę do wspólnego zbioru, wpisując tytuł, autora lub autorów (autorzy są encjami: imię i nazwisko), numer ISBN i rok wydania, opcjonalny opis (widoczny dla wszystkich, oddzielny od komentarzy czytelników), wybierając kategorię ze stałej listy oraz dodając do pięciu tagów (tagi są encjami). Tytuł, autor, ISBN i rok wydania są polami wymaganymi; tytuł, autor i ISBN podlegają walidacji. Priority: must-have
  > Socrates: Counter-argument considered: "każdy może dodać wpis o błędnym tytule lub autorze." Resolution: kept; pola tytuł i autor wymagane i walidowane, a przy porównywaniu duplikatów ignorowane są wielkość liter i zbędne spacje.
- FR-005: System wyświetla informację przed dodaniem książki, jeśli jest ona duplikatem. Porównanie opiera się na tytule i autorze (z pominięciem wielkości liter i zbędnych spacji) oraz na kodzie ISBN wpisanym przez użytkownika przy dodawaniu. Decyzja o dodaniu należy do użytkownika dodającego książkę. Priority: must-have
  > Socrates: Counter-argument considered: "różne wydania tej samej książki to prawdziwe duplikaty lub nie." Resolution: kept; użytkownik wpisuje przy dodawaniu kod ISBN, na podstawie którego również wykonywane jest porównanie.
- FR-006: Autor wpisu książki może wprowadzić w niej zmiany po wcześniejszym potwierdzeniu. Priority: must-have
  > Socrates: Counter-argument considered: "tylko autor może poprawić literówkę we wspólnym wpisie." Resolution: kept; opcje awaryjne (poprawa cudzego wpisu) wrócą później.
- FR-007: Autor wpisu książki może ją usunąć. Usunięta książka znika ze wszystkich list, a oceny i komentarze innych użytkowników pozostają zachowane (usunięcie nie kasuje danych). Priority: must-have
  > Socrates: Counter-argument considered: "usunięcie książki kasuje cudze oceny i komentarze." Resolution: usunięta książka nie jest widoczna na żadnej liście, a dane pozostają zachowane.

### Czytanie i oceny

- FR-008: Zalogowany użytkownik może w ramach swojego konta oznaczyć książkę jako przeczytaną i wystawić ocenę (skala 1–5). Priority: must-have
  > Socrates: Counter-argument considered: "komentarz to osobny koszt, a rekomendacja z niego nie korzysta." Resolution: komentarz wydzielony do FR-009 jako nice-to-have; FR-008 obejmuje tylko status przeczytania i ocenę.
- FR-009: Zalogowany użytkownik może w ramach swojego konta dodać do przeczytanej książki komentarz. Priority: nice-to-have

### Rekomendacje

- FR-010: Strona główna pokazuje zalogowanemu użytkownikowi do trzech rekomendacji kolejnych książek, budowanych na podstawie ocen wszystkich użytkowników, którzy przeczytali daną książkę, i nastawionych na kategorie książek przeczytanych przez niego w ciągu ostatniego roku. Priority: must-have
  > Socrates: Counter-argument considered: "kilka kroków reguły (kategoria, inna kategoria, limit) to duży koszt pierwszego przepływu." Resolution: kept; użytkownik podał regułę w wersji z kolejnymi kategoriami (patrz Business Logic): książki o najwyższych ocenach, z kategorii najczęściej czytanej w ostatnim roku, jeszcze nieprzeczytane; jeśli nie ma trzech, powtórka dla drugiej najpopularniejszej kategorii.

## Non-Functional Requirements

- Strona główna z rekomendacjami jest widoczna dla zalogowanego użytkownika w czasie krótszym niż 2 sekundy od wejścia na nią.
- Oceny, komentarze i status przeczytania nie są widoczne dla osób niezalogowanych.
- Zapisane książki, oceny i komentarze są dostępne po ponownym zalogowaniu i po restarcie aplikacji.
- Aplikacja jest używalna w dwóch ostatnich głównych wersjach czterech popularnych przeglądarek desktopowych.

## Business Logic

Aplikacja rekomenduje użytkownikowi do trzech nieprzeczytanych przez niego książek o najwyższych ocenach, wybranych z kategorii, które czytał najczęściej w ostatnim roku, a gdy nie uzbiera trzech, powtarza wybór dla jego drugiej najczęściej czytanej kategorii.

**Dane wejściowe:** Oceny wystawione przez wszystkich użytkowników (średnia ocen książki, bez minimalnej liczby ocen). Książki, które dany użytkownik oznaczył jako przeczytane. Data oznaczenia książki jako przeczytanej, która określa, co liczy się jako „ostatni rok”. Kategoria każdej książki.

**Kroki:** (1) Z książek wybierane są te o najwyższych średnich ocenach. (2) Z tej listy brane są książki z kategorii najczęściej czytanej przez użytkownika w ostatnim roku, których użytkownik jeszcze nie przeczytał. (3) Jeśli nie da się wybrać trzech, krok 2 jest powtarzany dla drugiej najczęściej czytanej kategorii. Jeśli użytkownik czytał tylko jedną kategorię, wyświetla się tyle rekomendacji, ile system zdołał wygenerować.

**Wynik:** Do trzech rekomendowanych książek na stronie głównej. Gdy system nie wygeneruje żadnej, użytkownik widzi komunikat wyjaśniający, np. „Przeczytaj i oceń kilka książek, aby dostać rekomendacje”.

## Access Control

**Logowanie:** e-mail + hasło. Każdy domownik ma własne konto. Konta zakłada właściciel ręcznie; w MVP nie ma samodzielnej rejestracji.

**Niezalogowany użytkownik:** Może przeglądać i wyszukiwać książki (tytuł, autor, kategoria). Nie widzi ocen, komentarzy, statusu przeczytania ani rekomendacji. Logowanie jest wymagane do dodawania, edycji i usuwania książek oraz do oznaczania jako przeczytana i oceniania.

**Role:** Brak ról administracyjnych w aplikacji. Wszyscy zalogowani widzą wszystkie książki i mogą dodawać nowe.

**Edycja i usuwanie książki:** Tylko użytkownik, który dodał daną książkę (autor wpisu).

**Oceny, status przeczytania i komentarze:** Osobiste dla każdego użytkownika.

Nierozstrzygnięte kwestie dostępu (zakładanie kont przez właściciela, los książek po usunięciu konta autora) są w `## Open Questions`.

## Non-Goals

- **Samodzielna rejestracja kont:** konta zakłada właściciel ręcznie, bo użytkowników jest 2–4.
- **Pobieranie danych książki z zewnętrznego serwisu po ISBN:** dane wpisuje użytkownik ręcznie, bez integracji zewnętrznych.
- **Rekomendacje oparte na podobieństwie gustu między użytkownikami:** zostaje reguła ze średnią ocen i kategorią.
- **Wiele oddzielnych bibliotek lub gospodarstw domowych:** jeden wspólny zbiór dla wszystkich kont.
- **Import wielu książek naraz (np. z pliku):** książki dodawane są pojedynczo przez formularz.

## Open Questions

1. **Czym jest „rok” w polach książki** — rok dodania do zbioru czy rok wydania? — Owner: użytkownik. Resolved: rok wydania, pole wymagane.
2. **Czy kategoria pochodzi ze stałej listy, czy jest wolnym tekstem, i jakie są kategorie?** Wybór wpływa na zliczanie „najczęściej czytanej” kategorii. — Owner: użytkownik. Block: yes (reguła rekomendacji zależy od spójnych kategorii). Resolved: stała lista kategorii w kodzie (`App\Enum\Category`), zmiana przez kod i wdrożenie.
3. **Do czego służą tagi i notatka książki?** Reguła rekomendacji ich nie używa; są w FR-004 jako część wpisu. Czy to must-have w MVP? — Owner: użytkownik. Resolved: tagi są encjami, do pięciu na książkę; notatka książki wypada z FR-004 (zastępują ją komentarze czytelników, o których zdecyduje się później, patrz roadmapa, pytanie 11); dodano opcjonalny opis.
4. **Jak właściciel zakłada konta i kto nim jest** (poza aplikacją czy ekran administracyjny). — Owner: użytkownik.
5. **Co dzieje się z książkami, gdy konto ich autora zostanie usunięte.** — Owner: użytkownik.
6. **Czy ISBN jest polem wymaganym i jak łączą się dopasowania po ISBN oraz po tytule i autorze** przy ostrzeżeniu o duplikacie. — Owner: użytkownik. Resolved: ISBN jest polem wymaganym i walidowanym; zasady łączenia dopasowań w ostrzeżeniu o duplikacie wchodzą z S-06.
7. **Jaki jest przewidywany ruch (qps)?** Pole `target_scale.qps` nie zostało podane w notatkach. — Owner: użytkownik.
8. **Jaki jest przewidywany wolumen danych (data_volume)?** Pole `target_scale.data_volume` nie zostało podane w notatkach. — Owner: użytkownik.
