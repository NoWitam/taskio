# Wielojęzyczność aplikacji TaskIO

## Informacje ogólne

Aplikacja TaskIO obsługuje w pełni dwa języki:
- **Angielski (en)** - English
- **Polski (pl)** - Polski

System tłumaczeń jest zaimplementowany zarówno na stronie frontend'u (Vue 3) jak i backend'u (Laravel).

## Struktura tłumaczeń

### Frontend (Vue 3 / JavaScript)

Pliki tłumaczeń znajdują się w `resources/js/locales/`:
- `en.json` - tłumaczenia angielskie
- `pl.json` - tłumaczenia polskie

Struktura tłumaczeń jest hierarchiczna, np.:
```json
{
  "common": {
    "save": "Save",
    "cancel": "Cancel"
  },
  "tasks": {
    "title": "Tasks",
    "newTask": "New Task"
  }
}
```

### Backend (Laravel PHP)

Pliki tłumaczeń znajdują się w `lang/`:
- `lang/en/` - pliki tłumaczeń angielskich
- `lang/pl/` - pliki tłumaczeń polskich

Dostępne pliki:
- `changelog.php` - tłumaczenia dla modułu dziennika zmian
- `messages.php` - wspólne komunikaty
- `tasks.php` - tłumaczenia modułu zadań
- `labels.php` - tłumaczenia modułu etykiet

## Implementacja po stronie Frontend'u

### Komponent do zmiany języka

Komponent `LanguageSwitcher.vue` znajduje się w `resources/js/components/`

Używanie:
```vue
<LanguageSwitcher />
```

Komponent automatycznie pokaże:
- 🇬🇧 English (en)
- 🇵🇱 Polski (pl)

### Composable `useI18n`

Wszędzie w komponentach Vue3 możesz używać i18n poprzez composable:

```vue
<script setup>
import { useI18n } from '@/composables/useI18n';

const { t, currentLocale, setLocale } = useI18n();
</script>

<template>
  <div>
    <h1>{{ t('tasks.title') }}</h1>
    <p>{{ t('common.save') }}</p>
    
    <!-- Zmiana języka programowo -->
    <button @click="setLocale('pl')">Polski</button>
    <button @click="setLocale('en')">English</button>
  </div>
</template>
```

### Funkcja `t()` z parametrami

Dla tłumaczeń z dynamicznymi wartościami:

```javascript
// W locales/en.json:
// "validation": { "minLength": "Minimum {count} characters required" }

// W komponencie:
const errorMsg = t('validation.minLength', null, { count: 5 });
// Wynik: "Minimum 5 characters required"
```

## Implementacja po stronie Backend'u

### Użycie w Laravel

```php
// W kontrollerach, modelach, itp.
$message = __('messages.created_successfully', ['Model' => 'Task']);
// Polish: "Zadanie zostało utworzone pomyślnie"

// Lub z przekazanym językiem
$message = __('tasks.status.done');
// Zwróci odpowiedni tekst w aktualnym języku aplikacji
```

### Ustawianie języka w Laravel

```php
// W middleware'ach lub kontrollerach
App::setLocale('pl'); // lub 'en'
```

## Inicjalizacja systemu

1. **Frontend**: Język jest automatycznie wybierany podczas inicjalizacji aplikacji w `resources/js/app.js`
   - Sprawdza localStorage dla zapisanego języka
   - Jeśli nie ma, sprawdza język przeglądarki
   - Defaultuje na angielski

2. **Backend**: Język Laravel'a jest sugerowany przez frontend poprzez nagłówek HTTP `Accept-Language` lub query parameter

## Dodawanie nowych tłumaczeń

### Frontend

1. Otwórz odpowiedni plik w `resources/js/locales/`
2. Dodaj klucz translacji:
```json
{
  "myModule": {
    "myKey": "New English Translation",
    "anotherKey": "Another translation"
  }
}
```
3. Powtórz dla drugiego języka

### Backend

1. Otwórz plik w `lang/en/` lub `lang/pl/`
2. Dodaj klucz:
```php
'myKey' => 'New translation text',
```
3. Powtórz dla drugiego języka w `lang/pl/`

## Przechowywanie preferencji użytkownika

Wybrany język jest przechowywany w:
- **localStorage** na frontencie (`key: 'locale'`)
- Opcjonalnie w bazie danych (to wymagać będzie dodatkówej implementacji)

## Komponenty już z tłumaczeniami

Następujące komponenty są już w pełni zlokalizowane:
- ✅ LanguageSwitcher.vue
- ✅ Navbar.vue
- ✅ TasksView.vue
- ✅ CreateLabelForm.vue
- ✅ AppLayout.vue

## Komponenty wymagające tłumaczeń

Te komponenty wciąż zawierają hardkodowane teksty i powinny być zaktualizowane:
- ⚠️ DashboardView.vue
- ⚠️ Example.vue (komponent demonstracyjny)
- ⚠️ LabelSelect.vue
- ⚠️ Komponenty modułu zadań
- ⚠️ Komponenty komentarzy

## Best Practices

1. **Zawsze używaj klucze tłumaczeń**: Nie umieszczaj tekstu bezpośrednio w szablonach
   ```vue
   <!-- ❌ Źle -->
   <p>Nowe zadanie</p>
   
   <!-- ✅ Dobrze -->
   <p>{{ t('tasks.newTask') }}</p>
   ```

2. **Organizuj klucze logicznie**: Grupuj powiązane tłumaczenia
   ```json
   "tasks": {
     "title": "Tasks",
     "newTask": "New Task",
     "deleteConfirm": "Delete task?"
   }
   ```

3. **Używaj sprzedaż dla wspólnych tekstów**: Tekst "Save" jest taki sam wszędzie
   ```json
   "common": {
     "save": "Save",
     "cancel": "Cancel"
   }
   ```

4. **Przechowuj klucze w camelCase**: Dla spójności
   ```json
   "myModule": { "myKey": "Value" }  // ✅ Dobrze
   "my_module": { "my_key": "Value" } // ❌ Unikać
   ```

## Testowanie tłumaczeń

1. Otwórz aplikację w przeglądarce
2. Kliknij na LanguageSwitcher w górnym prawy górnym rogu Navbaru
3. Wybierz język
4. Sprawdź czy wszystkie teksty się zmieniają
5. Odśwież stronę - język powinien być zachowany

## Skrócona kontroli lista dla nowych modułów

- [ ] Dodaj tłumaczenia frontend'u do `resources/js/locales/en.json` i `pl.json`
- [ ] Dodaj tłumaczenia backend'u do `lang/en/` i `lang/pl/`
- [ ] Użyj `t()` w szablonach Vue zamiast hardkodowanych tekstów
- [ ] Użyj `__()` w kodzie PHP
- [ ] Przetestuj oba języki
