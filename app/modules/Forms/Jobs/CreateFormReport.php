<?php

namespace App\Modules\Forms\Jobs;

use App\Modules\Forms\Agents\FormReportAgent;
use App\Modules\Forms\Models\FormReport;
use App\Modules\Forms\Tools\QuerySubmissions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CreateFormReport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private FormReport $report
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->createView();

        $report = $this->generateReport();
        $this->saveReport($report);
        $this->report->markAsCompleted();

        $this->dropView();
    }

    public function failed(?Throwable $exception): void
    {
        $this->dropView();
    }

    /**
     * Generate report using Laravel AI SDK agent with tool calling.
     * The agent will automatically execute multiple tool calls iteratively
     * until it gathers enough data to generate a complete report.
     */
    private function generateReport(): string
    {
        $prompt = $this->buildPrompt();
        $tool = new QuerySubmissions($this->report);
        $agent = new FormReportAgent($tool);

        $response = $agent->prompt(
            prompt: $prompt,
            provider: 'openai',
            model: 'gpt-4o'
        );

        return $response->text;
    }

    /**
     * Build complete prompt for the AI agent
     */
    private function buildPrompt(): string
    {
        $viewName = $this->report->getViewName();
        $eavViewName = $this->getEavViewName();
        $form = $this->report->form;
        $jsonSchema = json_encode($form->getJsonSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Extract field paths for column documentation
        $fieldPaths = $this->extractFieldPaths($form->getJsonSchema());
        $fieldColumns = $this->formatFieldColumns($fieldPaths);
        
        $prompt = <<<PROMPT
            Jesteś specjalistą od analizy danych formularzy. Twoim zadaniem jest wygenerowanie szczegółowego raportu analitycznego.

            Masz dostęp do DWÓCH widoków bazy danych zawierających wypełnienia formularza:

            BAZA DANYCH - PostgreSQL 16:
            Pracujesz na bazie PostgreSQL 16.6. Dane formularza są przechowywane w ZNORMALIZOWANEJ strukturze kolumnowej.
            
            ⚠️ WAŻNE - UŻYWAJ STANDARDOWEGO SQL:
            - NIE MA operatorów JSON (->, ->>, @>, ?, ?&, ?|)
            - NIE MA kolumny 'data' typu JSONB
            - Wszystkie pola formularza są OSOBNYMI KOLUMNAMI w widoku
            - Używaj nazw kolumn bezpośrednio: SELECT email, COUNT(*) ... GROUP BY email
            
            DOSTĘPNE WIDOKI:
            
            1. WIDOK GŁÓWNY (PIVOT) - '{$viewName}' - UŻYJ TEGO DO ANALIZ:
                Każdy wiersz = jedno wypełnienie formularza
                Każda kolumna = jedno pole formularza lub atrybut systemowy
                
                KOLUMNY SYSTEMOWE:
                - submission_id (UUID) - ID wypełnienia
                - source (TEXT) - źródło wypełnienia ('task' lub 'form')
                - creator_id (UUID) - ID twórcy wypełnienia
                - creator_name (TEXT) - imię i nazwisko twórcy
                - created_at (TIMESTAMP) - data zatwierdzenia wypełnienia
                
                KOLUMNY PÓL FORMULARZA:
                {$fieldColumns}
            
            2. WIDOK EAV (opcjonalny) - '{$eavViewName}' - TYLKO JEŚLI POTRZEBNE:
                Każdy wiersz = jedno pole w jednym wypełnieniu
                Użyj tylko gdy potrzebujesz surowych danych lub dynamicznej analizy pól
                
                Kolumny:
                - submission_id, source, creator_id, creator_name, created_at
                - field_path (TEXT) - ścieżka pola (np. 'email', 'address.city')
                - field_key (TEXT) - klucz pola
                - field_value (TEXT) - wartość tekstowa
                - field_value_numeric (DECIMAL) - wartość numeryczna (jeśli pole jest liczbą)
                - field_value_boolean (BOOLEAN) - wartość logiczna (jeśli pole jest boolean)
                - field_value_date (TIMESTAMP) - wartość daty (jeśli pole jest datą)
                - field_type (TEXT) - typ pola ('string', 'number', 'boolean', 'date')


            STRUKTURA FORMULARZA (JSON Schema):
            Poniżej znajdziesz pełną strukturę formularza z definicjami wszystkich pól:
            {$jsonSchema}

            INTERPRETACJA STRUKTURY:
            - Każde pole ma 'id' (nazwa kolumny w widoku PIVOT), 'label' (opis), 'type' (typ pola)
            - ⚠️ WAŻNE: Nazwy kolumn w widoku PIVOT NIE SĄ RÓWNE 'id' z JSON schema!
            - Patrz na listę "KOLUMNY PÓL FORMULARZA" powyżej - tam są PRAWDZIWE nazwy kolumn w SQL
            - Przykład: jeśli pole o id "dzial" jest w sekcji "dane_pracownika", to kolumna to "dane_pracownika_dzial"
            - Pola typu 'section' grupują inne pola - są spłaszczone do formatu: section_id_field_id
            - Pola typu 'repeater' to tablice - format: repeater_id_n_field_id (gdzie n to indeks)
            - Pola typu 'select', 'radio', 'checkbox_group' mają config.options - lista możliwych wartości
            - Pola numeryczne: 'number', 'rating'
            - Pola logiczne: 'checkbox', 'toggle'
            - Pola tekstowe: 'short_text', 'long_text', 'email', 'url', 'phone'
            - Pola daty: 'date', 'datetime'


            Interpretacja source:
            - 'task' = wypełnienie dodane przez wykonanie zadania
            - 'form' = wypełnienie dodane ręcznie w module formularzy

            KONTEKST FORMULARZA:
            - Nazwa: {$form->name}
            - Opis: {$form->description}

            PARAMETRY RAPORTU:
            - Nazwa raportu: {$this->report->name}
            - Okres: {$this->report->submissions_from->format('d.m.Y')} - {$this->report->submissions_to->format('d.m.Y')}
            - Źródła danych: {$this->formatSources()}
        PROMPT;

        // Add custom guidelines if provided
        if (!empty($this->report->guidelines)) {
            $prompt .= "\n\nDODATKOWE WYTYCZNE OD UŻYTKOWNIKA:\n{$this->report->guidelines}";
        }

        $prompt .= <<<PROMPT


            STRATEGIA ANALIZY - PODEJDŹ PROAKTYWNIE I DOGŁĘBNIE:
            Nie ograniczaj się TYLKO do dosłownych wytycznych użytkownika - myśl SZEROKO i GŁĘBOKO.
            
            1. ZIDENTYFIKUJ KONTEKST I POWIĄZANE ZAGADNIENIA:
               - Jeśli użytkownik pyta o X, zastanów się jakie aspekty Y i Z są z tym powiązane
               - Szukaj trendów czasowych (czy coś się zmienia w czasie?)
               - Szukaj korelacji (czy X wpływa na Y? Jakie są zależności?)
               - Identyfikuj anomalie i nietypowe wzorce
               - Porównuj grupy (różne źródła, różni twórcy, okresy czasu)
               - Zadawaj pytania: "Co jeszcze mogłoby być interesujące w tym kontekście?"
            
            2. WIELOWYMIAROWA ANALIZA - MINIMUM 20-30 ZAPYTAŃ SQL:
               a) PODSTAWOWE STATYSTYKI:
                  - COUNT, SUM, AVG, MIN, MAX, STDDEV
                  - Rozkłady i percentyle (25%, 50%, 75%, 90%, 95%)
                  - Mody (najczęstsze wartości)
               
               b) ANALIZA CZASOWA:
                  - Trendy dzienne/tygodniowe/miesięczne
                  - Porównanie okresów (początek vs koniec okresu)
                  - Dni tygodnia / godziny (jeśli relevant)
                  - Sezonowość i wzorce
               
               c) SEGMENTACJA I GRUPOWANIE:
                  - Według twórców (top użytkownicy, rozkład aktywności)
                  - Według źródeł (task vs form)
                  - Według kategorii/wartości w polach
                  - Wielopoziomowe grupowanie
               
               d) JAKOŚĆ I KOMPLETNOŚĆ:
                  - Które pola są najczęściej wypełniane?
                  - Które są pomijane?
                  - Analiza pustych/niepełnych wartości
                  - Wzorce wypełniania
               
               e) KORELACJE I ZALEŻNOŚCI:
                  - Czy pole A wpływa na pole B?
                  - Cross-tabulacje
                  - Grupowe porównania
               
               f) ANOMALIE I OUTLIERS:
                  - Wartości odstające
                  - Nietypowe kombinacje
                  - Podejrzane wzorce
            
            3. TECHNIKI ZAPYTAŃ SQL - STANDARDOWY SQL, BEZ OPERATORÓW JSON:
               ⚠️ KRYTYCZNIE WAŻNE - UŻYWAJ NAZW KOLUMN Z LISTY "KOLUMNY PÓL FORMULARZA" nie z 'id' w JSON!
               - Jeśli pole jest w sekcji: section_id_field_id (np. dane_pracownika_dzial, NIE samo "dzial")
               - Jeśli pole jest w repeaterze: repeater_id_n_field_id (np. historia_n_rok)
               - Pierwszym zapytaniem zawsze: SELECT * FROM {$viewName} LIMIT 1 aby zobaczyć RZECZYWISTE nazwy kolumn!
               - Potem: SELECT COUNT(*) FROM {$viewName} dla overview
               - Używaj nazw kolumn bezpośrednio: SELECT dane_pracownika_email, COUNT(*) FROM {$viewName} GROUP BY dane_pracownika_email
               - Patrz na JSON Schema aby zrozumieć:
                 * Jakie wartości są możliwe (dla select/radio użyj config.options)
                 * Jaki typ danych (number = agregacje AVG/SUM, boolean = TRUE/FALSE, date = DATE_TRUNC)
                 * Jakie pola są związane (w tej samej sekcji = mogą być skorelowane)
               - Używaj GROUP BY dla segmentacji
               - Używaj agregacji dla dużych zbiorów
               - Filtrowanie: WHERE email = 'test@example.com', WHERE age > 18
               - LIMIT 50 dla wyników dużych zbiorów
               - Window functions dla rankingów: ROW_NUMBER(), RANK()
               - CASE WHEN dla kategoryzacji
               - Percentyle: PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age)
               - DATE_TRUNC dla analizy czasowej: DATE_TRUNC('day', created_at)
               - Agregacje numeryczne: AVG(age), SUM(amount), MAX(score)
               
               PRZYKŁADY POPRAWNYCH ZAPYTAŃ (WIDOK GŁÓWNY):
               ✅ SELECT * FROM {$viewName} LIMIT 1  -- ZAWSZE ZACZNIJ OD TEGO!
               ✅ SELECT COUNT(*) FROM {$viewName}
               ✅ SELECT source, COUNT(*) FROM {$viewName} GROUP BY source
               ✅ SELECT creator_name, COUNT(*) FROM {$viewName} GROUP BY creator_name ORDER BY COUNT(*) DESC LIMIT 10
               ✅ SELECT DATE(created_at), COUNT(*) FROM {$viewName} GROUP BY DATE(created_at)
               ✅ SELECT dane_pracownika_email FROM {$viewName} WHERE dane_pracownika_email IS NOT NULL  -- pole w sekcji!
               ✅ SELECT dane_pracownika_dzial, COUNT(*) FROM {$viewName} GROUP BY dane_pracownika_dzial  -- pole w sekcji!
               ✅ SELECT AVG(ocena_wsparcia_managera) FROM {$viewName}  -- jeśli na liście kolumn tak się nazywa
               
               PRZYKŁADY ZAPYTAŃ Z WIDOKU EAV (opcjonalnie):
               ✅ SELECT field_path, COUNT(DISTINCT submission_id) FROM {$eavViewName} GROUP BY field_path
               ✅ SELECT field_path, field_value, COUNT(*) FROM {$eavViewName} GROUP BY field_path, field_value
               
               ⛔ NIE UŻYWAJ (błędne):
               ❌ SELECT data->>'email' FROM {$viewName}  -- NIE MA kolumny 'data'!
               ❌ SELECT * FROM {$viewName} WHERE data @> '{{"status":"active"}}'  -- NIE MA kolumny 'data'!
               ❌ SELECT data ? 'email' FROM {$viewName}  -- NIE MA kolumny 'data'!
               ❌ SELECT dzial FROM {$viewName}  -- BŁĄD jeśli pole jest w sekcji! Użyj dane_pracownika_dzial
               ❌ SELECT 'it' = ANY(dzial)  -- Kolumny nie są tablicami! Użyj WHERE dzial = 'it'
               ❌ SELECT ocena_wsparcia FROM {$viewName}  -- Sprawdź listę kolumn! Może to dane_pracownika_ocena_wsparcia
            
            4. PRZYKŁAD GŁĘBOKIEJ ANALIZY:
               Jeśli użytkownik pyta: "Ile mamy wypełnień formularza kontaktowego?"
               
               NIE RÓB TYLKO:
               → SELECT COUNT(*) FROM {$viewName}
               
               ZRÓB SZEROKO (20-30 zapytań), PAMIĘTAJ O PREFIKSACH SEKCJI:
               → 1. SELECT * FROM {$viewName} LIMIT 1  -- SPRAWDŹ NAZWY KOLUMN!
               → 2. SELECT COUNT(*) FROM {$viewName}
               → 3. SELECT source, COUNT(*) FROM {$viewName} GROUP BY source
               → 4. SELECT DATE(created_at), COUNT(*) FROM {$viewName} GROUP BY DATE(created_at)
               → 5. SELECT creator_name, COUNT(*) FROM {$viewName} GROUP BY creator_name ORDER BY COUNT(*) DESC LIMIT 10
               → 6. SELECT EXTRACT(HOUR FROM created_at), COUNT(*) FROM {$viewName} GROUP BY EXTRACT(HOUR FROM created_at)
               → 7-20. Szczegółowa analiza każdego pola z listy "KOLUMNY PÓL FORMULARZA" (używaj DOKŁADNYCH nazw!)
               → 21-30. Crossowe analizy i wnioski
            
            5. WYMAGANA STRUKTURA RAPORTU (KONIECZNIE!):
            
               ## 📊 Streszczenie Wykonawcze
               - 3-5 najważniejszych wniosków (numerowana lista)
               - Kluczowe liczby i metryki
               - Główne rekomendacje
               
               ## 📈 Analiza Podstawowa
               - Statystyki ogólne (ile rekordów, zakres dat, źródła)
               - Podstawowe rozkłady
               - Tabele z kluczowymi metrykami
               
               ## 🔍 Analiza Szczegółowa (Według Wytycznych)
               - Dogłębna analiza dokładnie tego co prosił użytkownik
               - Wielowymiarowe spojrzenie na zagadnienie
               - Trendy i wzorce w analizowanym obszarze
               - Segmentacja i grupowanie danych
               
               ## 💡 Analiza Powiązana i Dodatkowe Obserwacje (PROAKTYWNA!)
               - Dodatkowe insighty wykraczające poza wytyczne
               - Nietypowe wzorce i anomalie
               - Korelacje i zależności między zmiennymi
               - Porównania i benchmarki
               - "Co jeszcze ciekawego znalazłem w danych..."
               
               ## ✅ Wnioski i Rekomendacje
               - Konkretne wnioski z całej analizy (numerowana lista)
               - Praktyczne rekomendacje działań
               - Obszary wymagające uwagi lub poprawy
               - Sugestie dalszych analiz

            FORMATOWANIE (BARDZO WAŻNE!):
            - Używaj tabel Markdown dla danych tabelarycznych
            - Używaj list numerowanych dla rankingów i wniosków
            - Używaj wykresów ASCII dla wizualizacji trendów (jeśli pomocne)
            - Liczby formatuj czytelnie: 1 234 zamiast 1234, 45.2% zamiast 0.452
            - Używaj polskiego języka
            - Sekcje główne: ## oraz podsekcje: ###
            - Kluczowe metryki: **pogrubienie**
            - Emoji dla czytelności: 📊 📈 📉 ⚠️ ✅ ❌ 💡 🔍 ⭐ 👥
            - Cytaty dla ważnych wniosków: > **Kluczowy wniosek:** ...
            - Kod inline dla wartości: `123 wypełnień`
            - Używaj horizontal rules (---) między sekcjami

            KRYTERIA SUKCESU:
            ✅ Raport ma być KOMPLEKSOWY i WYCZERPUJĄCY (minimum 1500 słów)
            ✅ Minimum 20-30 różnych zapytań SQL wykonanych
            ✅ Wszystkie 5 sekcji obecne i szczegółowe
            ✅ Nie tylko odpowiadasz na pytanie - WYCHODZISZ NAPRZÓD z dodatkowymi insightami
            ✅ Konkretne dane, liczby, tabele w każdej sekcji
            ✅ Wnioski są PRAKTYCZNE i ACTIONABLE
            ✅ Czytelne formatowanie z emoji i strukturami

            Przeanalizuj dane z widoku '{$viewName}' i wygeneruj DOGŁĘBNY, KOMPLEKSOWY, PROAKTYWNY raport analityczny.
        PROMPT;

        return $prompt;
    }

    /**
     * Format sources array for prompt
     */
    private function formatSources(): string
    {
        if(!empty($this->report->sources)) {
            return collect($this->report->sources)
                ->map(function ($source) {
                    return match ($source) {
                        'task' => 'zadania',
                        'form' => 'ręczne wypełnienia',
                        default => $source
                    };
                })
                ->join(', ');
        }

        return 'nie sprecyzowano - wszystkie są dozwolone';
    }

    /**
     * Save report to storage
     */
    private function saveReport(string $content): void
    {
        $filename = sprintf(
            'raport_%s_%s.md',
            $this->report->form->id,
            now()->format('Y-m-d_His')
        );

        $this->report->file()->create([
            'name' => $filename,
            'type' => 'document',
            'size' => strlen($content),
            'path' => 'reports/' . $filename,
            'mime_type' => 'text/plain',
            'uploader_id' => $this->report->creator_id
        ]);

        Storage::put('reports/' . $filename, $content);
    }

    /**
     * Create database views for submissions.
     * Creates TWO materialized views:
     * 1. EAV view - raw field data (rows per field)
     * 2. PIVOT view - columnar data for AI (rows per submission)
     */
    private function createView(): void
    {
        $eavViewName = $this->getEavViewName();
        $pivotViewName = $this->report->getViewName();
        
        // Extract field paths from form schema
        $fieldPaths = $this->extractFieldPaths($this->report->form->getJsonSchema());
        
        // Build WHERE clause for filtering
        $whereClause = "
            fs.form_id = '{$this->report->form_id}'
            AND fs.approved_at IS NOT NULL
            AND fs.deleted_at IS NULL
            AND fs.approved_at >= '{$this->report->submissions_from->format('Y-m-d')}'
            AND fs.approved_at <= '{$this->report->submissions_to->format('Y-m-d')}'
        ";

        if(!empty($this->report->sources)) {
            $sources = implode("', '", $this->report->sources);
            $whereClause .= " AND fs.submittable_type IN ('{$sources}')";
        }

        // VIEW 1: EAV (Entity-Attribute-Value) - raw field data
        $eavStatement = "
            CREATE MATERIALIZED VIEW {$eavViewName} AS
            (
                SELECT 
                    fs.id as submission_id,
                    fs.submittable_type as source,
                    fs.creator_id,
                    u.name as creator_name,
                    fs.approved_at as created_at,
                    fsf.field_path,
                    fsf.field_key,
                    fsf.field_value,
                    fsf.field_value_numeric,
                    fsf.field_value_boolean,
                    fsf.field_value_date,
                    fsf.field_type
                FROM 
                    form_submissions fs
                LEFT JOIN users u ON u.id = fs.creator_id
                LEFT JOIN form_submission_fields fsf ON fsf.form_submission_id = fs.id
                WHERE {$whereClause}
            )
        ";

        DB::statement($eavStatement);

        // VIEW 2: PIVOT - columnar data for AI (one row per submission)
        $pivotColumns = $this->buildPivotColumns($fieldPaths);
        
        $pivotStatement = "
            CREATE MATERIALIZED VIEW {$pivotViewName} AS
            (
                SELECT 
                    submission_id,
                    source,
                    creator_id,
                    creator_name,
                    created_at";
        
        if (!empty($pivotColumns)) {
            $pivotStatement .= ",\n    {$pivotColumns}";
        }
        
        $pivotStatement .= "
                FROM {$eavViewName}
                GROUP BY submission_id, source, creator_id, creator_name, created_at
            )
        ";

        DB::statement($pivotStatement);
    }

    /**
     * Drop database view
     */
    private function dropView(): void
    {
        // Drop both views - PIVOT first (depends on EAV), then EAV
        // DB::statement('DROP MATERIALIZED VIEW IF EXISTS ' . $this->report->getViewName());
        // DB::statement('DROP MATERIALIZED VIEW IF EXISTS ' . $this->getEavViewName());
    }

    /**
     * Get the EAV view name (helper view with raw field data).
     */
    private function getEavViewName(): string
    {
        return $this->report->getViewName() . '_eav';
    }

    /**
     * Extract all field paths from form schema recursively.
     * Supports JSON Schema format (properties/type structure).
     */
    private function extractFieldPaths(array $schema, string $prefix = ''): array
    {
        $paths = [];

        // JSON Schema format: check for 'properties' key
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $fieldName => $fieldSchema) {
                $path = $prefix ? "{$prefix}.{$fieldName}" : $fieldName;
                
                // Get type from JSON Schema
                $type = $fieldSchema['type'] ?? 'string';
                
                // If it's an object (section), recurse into its properties
                if ($type === 'object' && isset($fieldSchema['properties'])) {
                    $paths = array_merge(
                        $paths,
                        $this->extractFieldPaths($fieldSchema, $path)
                    );
                } elseif ($type === 'array') {
                    // Arrays (like multi-select) are stored as TEXT with JSON
                    // We'll treat them as string columns for now
                    $paths[] = [
                        'path' => $path,
                        'type' => 'string', // Arrays stored as TEXT in field_value
                        'field' => array_merge(['id' => $fieldName, 'label' => $fieldName], $fieldSchema)
                    ];
                } else {
                    // Regular field - map JSON Schema type to our internal type
                    $internalType = $this->mapJsonSchemaType($type);
                    
                    $paths[] = [
                        'path' => $path,
                        'type' => $internalType,
                        'field' => array_merge(['id' => $fieldName, 'label' => $fieldName], $fieldSchema)
                    ];
                }
            }
        }

        return $paths;
    }

    /**
     * Map JSON Schema type to internal type.
     */
    private function mapJsonSchemaType(string $jsonSchemaType): string
    {
        return match($jsonSchemaType) {
            'integer', 'number' => 'number',
            'boolean' => 'boolean',
            'string' => 'string',
            default => 'string'
        };
    }

    /**
     * Detect field type from schema.
     */
    private function detectFieldType(string $schemaType): string
    {
        return match($schemaType) {
            'number', 'rating', 'integer' => 'number',
            'checkbox', 'toggle', 'boolean' => 'boolean',
            'date', 'datetime' => 'date',
            default => 'string'
        };
    }

    /**
     * Build PIVOT columns SQL for materialized view.
     */
    private function buildPivotColumns(array $fieldPaths): string
    {
        $columns = [];

        foreach ($fieldPaths as $pathInfo) {
            $path = $pathInfo['path'];
            $type = $this->detectFieldType($pathInfo['type']);
            
            // Skip repeater arrays themselves (we handle child fields)
            if ($pathInfo['type'] === 'repeater') {
                continue;
            }

            // Sanitize path for column name (replace dots and brackets with underscores)
            $columnName = str_replace(['.', '[', ']'], '_', $path);
            $columnName = preg_replace('/_+/', '_', $columnName); // Remove duplicate underscores
            $columnName = trim($columnName, '_');

            // Choose the appropriate value column based on type
            $valueColumn = match($type) {
                'number' => 'field_value_numeric',
                'boolean' => 'field_value_boolean',
                'date' => 'field_value_date',
                default => 'field_value'
            };

            // Add PIVOT column
            $columns[] = "MAX(CASE WHEN field_path = '{$path}' THEN {$valueColumn} END) as {$columnName}";
        }

        return implode(",\n    ", $columns);
    }

    /**
     * Format field columns for AI prompt.
     */
    private function formatFieldColumns(array $fieldPaths): string
    {
        $lines = [];

        foreach ($fieldPaths as $pathInfo) {
            $path = $pathInfo['path'];
            $type = $this->detectFieldType($pathInfo['type']);
            
            if ($pathInfo['type'] === 'repeater') {
                continue;
            }

            $columnName = str_replace(['.', '[', ']'], '_', $path);
            $columnName = preg_replace('/_+/', '_', $columnName);
            $columnName = trim($columnName, '_');

            $sqlType = match($type) {
                'number' => 'DECIMAL',
                'boolean' => 'BOOLEAN',
                'date' => 'TIMESTAMP',
                default => 'TEXT'
            };

            $label = $pathInfo['field']['label'] ?? $path;
            $lines[] = "- {$columnName} ({$sqlType}) - {$label}";
        }

        return implode("\n", $lines);
    }
}
