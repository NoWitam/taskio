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

        try {
            $report = $this->generateReport();
            $this->saveReport($report);
            $this->report->markAsCompleted();
        } finally {
            $this->dropView();
        }
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
        $form = $this->report->form;
        $jsonSchema = json_encode($form->getJsonSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        $prompt = <<<PROMPT
            Jesteś specjalistą od analizy danych formularzy. Twoim zadaniem jest wygenerowanie szczegółowego raportu analitycznego.

            Masz dostęp do widoku bazy danych '{$viewName}' który zawiera wypełnienia formularza.

            STRUKTURA DANYCH W WIDOKU:
            Kolumny:
            - data (JSON) - dane wypełnienia formularza zgodne ze schematem formularza
            - source (TEXT) - źródło wypełnienia
            - creator_id (UUID) - ID twórcy wypełnienia
            - creator_name (TEXT) - imię i nazwisko twórcy
            - created_at (TIMESTAMP) - data zatwierdzenia wypełnienia

            Interpretacja source:
            - 'task' = wypełnienie dodane przez wykonanie zadania
            - 'form' = wypełnienie dodane ręcznie w module formularzy

            STRUKTURA POLA DATA (JSON Schema):
            Kolumna 'data' w widoku zawiera dane wypełnień formularza w następującej strukturze:
            {$jsonSchema}

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


            STRATEGIA ANALIZY:
            Musisz wykonać WIELE zapytań SQL aby zebrać wszystkie potrzebne dane do raportu.
            Używaj narzędzia QuerySubmissions wielokrotnie - dla różnych aspektów analizy:
            
            PAMIĘTAJ:
            - Pierwszym zapytaniem zbierz podstawowe info o ilości danych
            - Używaj LIMIT 50 dla zapytań zwracających wiele wierszy (limit kontekstu)
            - Dla dużych zbiorów używaj agregacji (COUNT, AVG) zamiast pobierać surowe dane
            - Wykonuj zapytania stopniowo - najpierw overview, potem szczegóły
            - Możesz wykonać 10-15 zapytań aby zebrać kompletne dane

            FORMATOWANIE:
            - Używaj tabel Markdown dla danych tabelarycznych
            - Używaj wykresów ASCII dla wizualizacji (jeśli pomocne)
            - Liczby formatuj czytelnie (np. 1 234 zamiast 1234)
            - Używaj polskiego języka
            - Sekcje oznaczaj nagłówkami ##
            - Kluczowe metryki wyróżniaj **pogrubieniem**

            Przeanalizuj dane z widoku '{$viewName}' i wygeneruj kompleksowy raport.
        PROMPT;

        return $prompt;
    }

    /**
     * Format sources array for prompt
     */
    private function formatSources(): string
    {
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
     * Create database view for submissions
     */
    private function createView(): void
    {
        $sources = implode("', '", $this->report->sources);

        DB::statement("
            CREATE VIEW {$this->report->getViewName()} AS
            (
                SELECT 
                    fs.data as data,
                    fs.submittable_type as source,
                    fs.creator_id as creator_id,
                    u.name as creator_name,
                    fs.approved_at as created_at
                FROM 
                    form_submissions fs
                LEFT JOIN users u ON u.id = fs.creator_id
                WHERE 
                    fs.form_id = '{$this->report->form_id}'
                    AND fs.submittable_type IN ('{$sources}')
                    AND fs.approved_at IS NOT NULL
                    AND fs.deleted_at IS NULL
                    AND fs.approved_at >= '{$this->report->submissions_from->format('Y-m-d')}'
                    AND fs.approved_at <= '{$this->report->submissions_to->format('Y-m-d')}'
            )
        ");
    }

    /**
     * Drop database view
     */
    private function dropView(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . $this->report->getViewName());
    }
}
