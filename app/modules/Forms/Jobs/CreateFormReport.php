<?php

namespace App\Modules\Forms\Jobs;

use App\Modules\Forms\Agents\FormReportAgent;
use App\Modules\Forms\Models\FormReport;
use App\Modules\Forms\Services\FormAnalyticalTableService;
use App\Modules\Forms\Tools\QuerySubmissions;
use App\Modules\Forms\Traits\InteractsWithFormSchema;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class CreateFormReport implements ShouldQueue
{
    use InteractsWithFormSchema, Queueable;

    private bool $usesAnalyticalTable = false;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private FormReport $report
    ) {}

    /**
     * Execute the job.
     */
    public function handle(FormAnalyticalTableService $analyticalTableService): void
    {
        $form = $this->report->form;

        // Determine data source strategy based on form indexing status
        $this->usesAnalyticalTable = $form->isIndexed()
            && $analyticalTableService->tableExists($form);

        $report = $this->generateReport($analyticalTableService);
        $this->saveReport($report);
        $this->report->markAsCompleted();
    }

    /**
     * Generate report using Laravel AI SDK agent with tool calling.
     */
    private function generateReport(FormAnalyticalTableService $analyticalTableService): string
    {
        $prompt = $this->buildPrompt($analyticalTableService);
        $tool = new QuerySubmissions(
            report: $this->report,
            allowedTables: $this->getAllowedTableNames($analyticalTableService),
            formId: $this->usesAnalyticalTable ? null : $this->report->form_id,
        );
        $agent = new FormReportAgent($tool);

        $response = $agent->prompt(
            prompt: $prompt,
            provider: 'openai',
            model: 'gpt-4o'
        );

        return $response->text;
    }

    /**
     * Get the list of table/view names the AI agent is allowed to query.
     */
    private function getAllowedTableNames(FormAnalyticalTableService $analyticalTableService): array
    {
        if ($this->usesAnalyticalTable) {
            return [$analyticalTableService->getTableName($this->report->form)];
        }

        return ['form_submissions'];
    }

    /**
     * Get the primary data source name for the AI agent.
     */
    private function getDataSourceName(FormAnalyticalTableService $analyticalTableService): string
    {
        if ($this->usesAnalyticalTable) {
            return $analyticalTableService->getTableName($this->report->form);
        }

        return 'form_submissions';
    }

    /**
     * Build complete prompt for the AI agent.
     * Strategy depends on whether the form is indexed (analytical table) or not (form_submissions JSONB).
     */
    private function buildPrompt(FormAnalyticalTableService $analyticalTableService): string
    {
        $dataSourceName = $this->getDataSourceName($analyticalTableService);
        $form = $this->report->form;
        $jsonSchema = json_encode($form->getJsonSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($this->usesAnalyticalTable) {
            $fieldPaths = $this->extractFieldPaths($form->getJsonSchema());
            $fieldColumns = $this->formatFieldColumns($fieldPaths);
            $dataSourceDescription = $this->buildAnalyticalTableDescription($dataSourceName, $fieldColumns);
        } else {
            $dataSourceDescription = $this->buildFormSubmissionsDescription();
        }

        $prompt = <<<PROMPT
            Jesteś specjalistą od analizy danych formularzy. Twoim zadaniem jest wygenerowanie szczegółowego raportu analitycznego.

            BAZA DANYCH - PostgreSQL 16:
            Pracujesz na bazie PostgreSQL 16.6.
            
            {$dataSourceDescription}

            STRUKTURA FORMULARZA (JSON Schema):
            Poniżej znajdziesz pełną strukturę formularza z definicjami wszystkich pól:
            {$jsonSchema}

            INTERPRETACJA STRUKTURY:
            - Każde pole ma 'id' (nazwa klucza w JSON), 'label' (opis), 'type' (typ pola)
            - Pola typu 'section' grupują inne pola - sekcja to zagnieżdżony obiekt w JSON
            - Pola typu 'repeater' to tablice obiektów w JSON
            - Pola typu 'select', 'radio' mają config.options - lista możliwych wartości
            - Pola numeryczne: 'number', 'rating'
            - Pola logiczne: 'checkbox', 'toggle'
            - Pola tekstowe: 'short_text', 'long_text', 'email', 'url', 'phone'
            - Pola daty: 'date', 'datetime'

            Interpretacja submittable_type / source:
            - 'form' = wypełnienie dodane ręcznie w module formularzy
            - 'task' = wypełnienie dodane przez wykonanie zadania

            KONTEKST FORMULARZA:
            - Nazwa: {$form->name}
            - Opis: {$form->description}

            PARAMETRY RAPORTU:
            - Nazwa raportu: {$this->report->name}
            - Okres: {$this->report->submissions_from->format('d.m.Y')} - {$this->report->submissions_to->format('d.m.Y')}
            - Filtr źródeł danych: {$this->formatSources()} (UWAGA: to jest filtr — sprawdź w danych ile wypełnień pochodzi z każdego źródła. Raportuj TYLKO źródła które faktycznie mają dane.)
        PROMPT;

        if (!empty($this->report->guidelines)) {
            $prompt .= "\n\nDODATKOWE WYTYCZNE OD UŻYTKOWNIKA:\n{$this->report->guidelines}";
        }

        $prompt .= $this->buildAnalysisInstructions($dataSourceName);

        return $prompt;
    }

    /**
     * Build data source description for indexed forms (analytical table).
     */
    private function buildAnalyticalTableDescription(string $tableName, string $fieldColumns): string
    {
        $dateFrom = $this->report->submissions_from->format('Y-m-d');
        $dateTo = $this->report->submissions_to->format('Y-m-d');
        $sourceFilter = '';
        if (!empty($this->report->sources)) {
            $sources = implode("', '", $this->report->sources);
            $sourceFilter = "AND source IN ('{$sources}')";
        }

        return <<<DESC
            ŹRÓDŁO DANYCH - TABELA ANALITYCZNA:
            Formularz jest zaindeksowany. Dane znajdują się w dedykowanej tabeli analitycznej.
            Wszystkie pola formularza są OSOBNYMI KOLUMNAMI — używaj nazw kolumn bezpośrednio.
            
            ⚠️ WAŻNE - UŻYWAJ STANDARDOWEGO SQL:
            - NIE MA operatorów JSON (->, ->>, @>, ?, ?&, ?|) — z jednym wyjątkiem dla repeaterów
            - NIE MA kolumny 'data' typu JSONB
            - Używaj nazw kolumn bezpośrednio: SELECT email, COUNT(*) ... GROUP BY email
            
            ⚠️ FILTROWANIE DANYCH W ZAPYTANIACH:
            - Zakres dat: WHERE created_at >= '{$dateFrom} 00:00:00' AND created_at <= '{$dateTo} 23:59:59'
            {$sourceFilter}
            - UWAGA: porównując TIMESTAMP z datą, ZAWSZE dodawaj czas (00:00:00 lub 23:59:59)!
            
            TABELA: '{$tableName}'
                Każdy wiersz = jedno wypełnienie formularza
                Każda kolumna = jedno pole formularza lub atrybut systemowy
                
                KOLUMNY SYSTEMOWE:
                - submission_id (UUID) - ID wypełnienia
                - source (TEXT) - źródło wypełnienia ('task' lub 'form')
                - creator_id (UUID) - ID twórcy wypełnienia (człowiek, automatyzacja lub bot)
                - creator_name (TEXT) - imię i nazwisko twórcy-CZŁOWIEKA; NULL dla wypełnień
                  utworzonych przez automatyzację (workflow) lub bota
                - created_at (TIMESTAMP) - data zatwierdzenia wypełnienia
                - form_content_version_id (UUID) - wersja formularza
                
                KOLUMNY PÓL FORMULARZA:
                {$fieldColumns}
            
            ⚠️ UWAGA: Pola typu 'repeater' są przechowywane jako JSONB. Możesz używać operatorów JSONB
            (jsonb_array_elements, jsonb_each_text itp.) WYŁĄCZNIE na kolumnach repeaterów.
            Pozostałe kolumny to zwykłe typy SQL - używaj standardowego SQL.
        DESC;
    }

    /**
     * Build data source description for unindexed forms (form_submissions table with JSONB data).
     */
    private function buildFormSubmissionsDescription(): string
    {
        $formId = $this->report->form_id;
        $whereClause = $this->buildWhereClause();

        return <<<DESC
            ŹRÓDŁO DANYCH - TABELA form_submissions:
            Formularz NIE jest zaindeksowany. Dane wypełnień znajdują się w tabeli 'form_submissions'.
            Kolumna 'data' zawiera JSONB z danymi wypełnienia — MUSISZ UŻYWAĆ operatorów JSON do ekstrakcji pól.
            
            TABELA: 'form_submissions'
                Każdy wiersz = jedno wypełnienie formularza
                
                KOLUMNY:
                - id (UUID) - ID wypełnienia
                - form_id (UUID) - ID formularza
                - submittable_type (TEXT) - źródło wypełnienia ('form' lub inne typy)
                - submittable_id (UUID) - ID źródła
                - data (JSONB) - dane wypełnienia w formacie JSON
                - form_content_version_id (UUID) - wersja formularza
                - approved_at (TIMESTAMP, nullable) - data zatwierdzenia
                - creator_id (UUID) - ID twórcy (człowiek, automatyzacja lub bot)
                - creator_type (TEXT) - typ twórcy: 'user' (człowiek), 'workflow_run' (automatyzacja) lub 'bot'
                - created_at (TIMESTAMP) - data utworzenia
                - deleted_at (TIMESTAMP, nullable) - soft delete
            
            ⚠️ KRYTYCZNE ZABEZPIECZENIA:
            1. KAŻDE zapytanie MUSI zawierać: WHERE form_id = '{$formId}'
            2. Filtruj TYLKO zatwierdzone: AND approved_at IS NOT NULL
            3. Pomijaj usunięte: AND deleted_at IS NULL
            4. Filtruj zakres dat (uwzględnij cały dzień!): {$whereClause}
            5. ZAWSZE używaj LIMIT (max 50 rekordów na zapytanie)
            6. Do przeglądania wielu rekordów użyj LIMIT 50 OFFSET N (N = 0, 50, 100, ...)
            
            EKSTRAKCJA PÓL Z JSONB:
            Dane wypełnienia znajdują się w kolumnie 'data'. Struktura JSON odpowiada schematowi formularza.
            
            Operatory PostgreSQL JSONB:
            - data->>'klucz' — ekstrakcja tekstu (zwraca TEXT)
            - data->'klucz' — ekstrakcja JSONB (zwraca JSONB)
            - data->'sekcja'->>'pole' — zagnieżdżone pola w sekcjach
            - (data->>'pole_numeryczne')::NUMERIC — konwersja na typ numeryczny
            - (data->>'pole_logiczne')::BOOLEAN — konwersja na typ logiczny
            - data->'sekcja'->'pole_tablicowe' — pola wielokrotnego wyboru (JSONB array)
            - jsonb_array_elements_text(data->'sekcja'->'pole') — rozwinięcie tablicy JSONB
            
            PRZYKŁADY POPRAWNYCH ZAPYTAŃ:
            ✅ SELECT COUNT(*) FROM form_submissions WHERE form_id = '{$formId}' AND approved_at IS NOT NULL AND deleted_at IS NULL
            ✅ SELECT data->'dane_pracownika'->>'imie_i_nazwisko' as name, COUNT(*) FROM form_submissions WHERE form_id = '{$formId}' AND approved_at IS NOT NULL AND deleted_at IS NULL GROUP BY 1 LIMIT 50
            ✅ SELECT AVG((data->'ocena'->>'wynik')::NUMERIC) FROM form_submissions WHERE form_id = '{$formId}' AND approved_at IS NOT NULL AND deleted_at IS NULL
            
            ⛔ NIE UŻYWAJ:
            ❌ SELECT * FROM form_submissions — brak filtra form_id!
            ❌ SELECT data FROM form_submissions WHERE form_id = '{$formId}' — nie wyciągaj surowego JSONA, używaj operatorów
        DESC;
    }

    /**
     * Build analysis strategy and formatting instructions for the prompt.
     */
    private function buildAnalysisInstructions(string $dataSourceName): string
    {
        $isIndexed = $this->usesAnalyticalTable;
        $formId = $this->report->form_id;

        // Dynamic SQL examples based on data source type
        if ($isIndexed) {
            $sqlExamples = <<<SQL
               PRZYKŁADY POPRAWNYCH ZAPYTAŃ:
               ✅ SELECT * FROM {$dataSourceName} LIMIT 1  -- ZAWSZE ZACZNIJ OD TEGO!
               ✅ SELECT COUNT(*) FROM {$dataSourceName}
               ✅ SELECT source, COUNT(*) FROM {$dataSourceName} GROUP BY source
               ✅ SELECT creator_name, COUNT(*) FROM {$dataSourceName} GROUP BY creator_name ORDER BY COUNT(*) DESC LIMIT 10
               ✅ SELECT DATE(created_at), COUNT(*) FROM {$dataSourceName} GROUP BY DATE(created_at)
               
               ⚠️ WAŻNE:
               - Nazwy kolumn to: section_id_field_id (np. dane_pracownika_dzial, NIE samo "dzial")
               - Pierwszym zapytaniem zawsze: SELECT * FROM {$dataSourceName} LIMIT 1 aby zobaczyć RZECZYWISTE nazwy kolumn!
            SQL;
        } else {
            $sqlExamples = <<<SQL
               PRZYKŁADY POPRAWNYCH ZAPYTAŃ:
               ✅ SELECT id, data FROM {$dataSourceName} WHERE form_id = '{$formId}' AND approved_at IS NOT NULL AND deleted_at IS NULL LIMIT 5 -- ZACZNIJ OD TEGO aby zobaczyć strukturę danych
               ✅ SELECT COUNT(*) FROM {$dataSourceName} WHERE form_id = '{$formId}' AND approved_at IS NOT NULL AND deleted_at IS NULL
               ✅ SELECT data->'sekcja'->>'pole' as val, COUNT(*) FROM {$dataSourceName} WHERE form_id = '{$formId}' AND approved_at IS NOT NULL AND deleted_at IS NULL GROUP BY 1 LIMIT 50
               ✅ SELECT AVG((data->'sekcja'->>'pole_numeryczne')::NUMERIC) FROM {$dataSourceName} WHERE form_id = '{$formId}' AND approved_at IS NOT NULL AND deleted_at IS NULL
               
               ⚠️ WAŻNE:
               - KAŻDE zapytanie MUSI mieć: WHERE form_id = '{$formId}' AND approved_at IS NOT NULL AND deleted_at IS NULL
               - Do przeglądania wielu rekordów: LIMIT 50 OFFSET N
               - Najpierw pobierz kilka przykładów (LIMIT 5), żeby poznać strukturę JSON w 'data'
            SQL;
        }

        return <<<PROMPT


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
            
            3. TECHNIKI ZAPYTAŃ SQL:
               {$sqlExamples}
               
               - Używaj GROUP BY dla segmentacji
               - Używaj agregacji dla dużych zbiorów
               - LIMIT 50 dla wyników dużych zbiorów
               - Window functions dla rankingów: ROW_NUMBER(), RANK()
               - CASE WHEN dla kategoryzacji
               - Percentyle: PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY val)
               - DATE_TRUNC dla analizy czasowej: DATE_TRUNC('day', created_at)
            
            4. WYMAGANA STRUKTURA RAPORTU (KONIECZNIE!):
            
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

            Przeanalizuj dane i wygeneruj DOGŁĘBNY, KOMPLEKSOWY, PROAKTYWNY raport analityczny.
        PROMPT;

        return $prompt;
    }

    /**
     * Format sources array for prompt
     */
    private function formatSources(): string
    {
        if (!empty($this->report->sources)) {
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
            'uploader_id' => $this->report->creator_id,
        ]);

        Storage::put('reports/' . $filename, $content);
    }

    /**
     * Build WHERE clause for submission filtering (used in unindexed path prompt).
     */
    private function buildWhereClause(): string
    {
        $parts = [
            "approved_at >= '{$this->report->submissions_from->format('Y-m-d')} 00:00:00'",
            "approved_at <= '{$this->report->submissions_to->format('Y-m-d')} 23:59:59'",
        ];

        if (!empty($this->report->sources)) {
            $sources = implode("', '", $this->report->sources);
            $parts[] = "submittable_type IN ('{$sources}')";
        }

        return 'AND ' . implode(' AND ', $parts);
    }
}
