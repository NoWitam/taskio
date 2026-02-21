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

Poniżej jest pełna lista ikon dostępnych jako `name` w komponencie `Icon`.

- `alert-triangle`
- `archive`
- `at-sign`
- `badge-check`
- `bell`
- `bell-off`
- `branch`
- `calendar`
- `calendar-clock`
- `chart-bar`
- `chart-line`
- `check`
- `check-circle`
- `chevron-down`
- `chevron-left`
- `chevron-right`
- `chevron-up`
- `circle-help`
- `clipboard`
- `clock`
- `cloud`
- `cloud-download`
- `cloud-upload`
- `columns`
- `copy`
- `database`
- `dots-horizontal`
- `dots-vertical`
- `download`
- `external-link`
- `eye`
- `eye-off`
- `file`
- `file-plus`
- `file-search`
- `file-text`
- `file-x`
- `filter`
- `flag`
- `folder`
- `folder-open`
- `folder-plus`
- `forward`
- `globe`
- `grid`
- `hash`
- `home`
- `image`
- `inbox`
- `info-circle`
- `key`
- `label`
- `link`
- `loader`
- `lock`
- `logout`
- `mail`
- `mail-open`
- `megaphone`
- `menu`
- `message`
- `minus-circle`
- `moon`
- `paperclip`
- `pause`
- `pause-circle`
- `pencil`
- `plus`
- `plug`
- `question-mark`
- `refresh`
- `redo`
- `repeat`
- `reply`
- `restore`
- `save`
- `search`
- `send`
- `server`
- `settings`
- `shield`
- `shield-check`
- `sliders-horizontal`
- `sort-ascending`
- `sort-descending`
- `sparkles`
- `signature`
- `stop`
- `stopwatch`
- `sun`
- `table`
- `tag`
- `timer`
- `trash`
- `trash-restore`
- `undo`
- `upload`
- `user`
- `users`
- `video`
- `webhook`
- `workflow`
- `x`
- `x-circle`

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
- Nazwa pliku musi być w `kebab-case` i odpowiadać `name` (np. `dots-horizontal.vue` → `name="dots-horizontal"`)

## Przykład zaawansowanego użycia

```vue
<Icon 
  name="home" 
  size="lg"
  class="text-blue-500 hover:text-blue-700"
/>
```

Klasy CSS mogą być używane do zmiany koloru i stylu.
