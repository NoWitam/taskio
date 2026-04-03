<?php

namespace App\Modules\Forms\Jobs;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

use function Laravel\Ai\agent;

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

    private function generateReport(): string
    {
        $viewName = $this->report->getViewName();
        $form = $this->report->form;
        
        $instructions = "
            Jesteś specjalistą od analizy danych formularzy. Twoim zadaniem jest wygenerowanie szczegółowego raportu analitycznego.
            
            Masz dostęp do widoku bazy danych '{$viewName}' który zawiera wypełnienia formularza.
            
            Kolumny w widoku:
            - data (JSON) - dane wypełnienia formularza
            - source (ENUM) - źródło wypełnienia (np. 'task' lub 'form')
            - creator_id (UUID) - ID twórcy wypełnienia
            - creator_name (string) - imię i nazwisko twórcy
            - created_at (datetime) - data zatwierdzenia wypełnienia

            Wartości source:
            - task - dodane przez wykonanie zadania w aplikacji z podpiętym formularzem
            - form - dodane ręcznie z modułu formularza
            
            Wygeneruj raport w formacie Markdown zawierający:
            1. Podsumowanie wykonawcze (liczba wypełnień, okres, źródła)
            2. Statystyki czasowe (rozkład wypełnień w czasie)
            3. Analizę użytkowników (top wypełniający, statystyki)
            4. Analizę danych z pól formularza (najczęstsze odpowiedzi, trendy)
            5. Wnioski i rekomendacje
            
            Używaj tabel Markdown, wykresów ASCII i jasnego formatowania.
            Wszystkie liczby formatuj czytelnie (np. 1,234 zamiast 1234).
            Używaj polskiego języka.
        ";

        $messages = [
            ['role' => 'user', 'content' => "
                Przeanalizuj dane z widoku '{$viewName}' i wygeneruj kompleksowy raport dla formularza '{$form->name}'.
                Okres raportowania: {$this->report->submissions_from->format('d.m.Y')} - {$this->report->submissions_to->format('d.m.Y')}
                
                Kontekst:
                - Nazwa formularza: {$form->name}
                - Opis formularza: {$form->description}
                - Nazwa raportu: {$this->report->name}
                
                Wykonaj następujące kroki:
                1. Pobierz wszystkie dane z widoku bazy danych
                2. Przeanalizuj strukturę danych w kolumnie 'data' (to JSON z odpowiedziami)
                3. Wygeneruj szczegółowe statystyki
                4. Stwórz czytelny raport w Markdown
            "]
        ];
        
        $response = agent($instructions, $messages)->prompt('Wygeneruj raport w formacie Markdown.');

        return (string) $response;
    }

    private function saveReport(string $content): void
    {
        $filename = sprintf(
            'raport_%s_%s.md',
            $this->report->form->id,
            now()->format('Y-m-d_His')
        );

        $file = $this->report->file()->create([
            'name' => $filename,
            'type' => 'text/markdown',
            'size' => strlen($content),
            'path' => 'reports/' . $filename,
        ]);

        // Zapisz zawartość do storage
        Storage::put('reports/' . $filename, $content);
    }

    private function createView()
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

    private function dropView(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . $this->report->getViewName());
    }
}
