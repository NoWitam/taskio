# System Ikon SVG

## Jak używać ikon

### Podstawowe użycie
```vue
<Icon name="home" />
<Icon name="home" size="sm" />
<Icon name="home" size="lg" />
<Icon name="home" size="32" />
<Icon name="home" :size="24" />
```

### Dostępne rozmiary
- `xs` - 16px
- `sm` - 20px (domyślnie dla menu)
- `md` - 24px (domyślnie)
- `lg` - 32px
- `xl` - 40px
- Liczba - dowolny rozmiar w pikselach

### Dostępne ikony
- `home` - Ikona domu (Dashboard)
- `check-circle` - Zaznaczony krąg (Tasks)
- `users` - Użytkownicy
- `settings` - Ustawienia
- `menu` - Hamburgera menu
- `x` - Zamknięcie
- `chevron-down` - Strzałka w dół
- `logout` - Wylogowanie
- `question-mark` - Nieznana ikona

## Jak dodać nową ikonę

### 1. Utwórz plik SVG
Stwórz nowy plik w `resources/js/assets/icons/nazwa-ikony.vue`:

```vue
<template>
  <g>
    <!-- Tutaj zawartość SVG -->
    <path d="..." />
    <circle r="..." />
  </g>
</template>
```

### 2. Używaj ikony
```vue
<Icon name="nazwa-ikony" />
```

## Ważne informacje

- Ikony powinny używać `<g>` jako element główny
- Zdefiniuj kształty bez `fill` (domyślnie używamy `stroke`)
- ViewBox jest zawsze `0 0 24 24`
- Ikony dziedziczą aktualny kolor (`currentColor`)

## Przykład zaawansowanego użycia

```vue
<Icon 
  name="home" 
  size="lg"
  class="text-blue-500 hover:text-blue-700"
/>
```

Klasy CSS mogą być używane do zmiany koloru i stylu.
