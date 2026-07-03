<?php

namespace App\Modules\Approvals\Agents;

use App\Modules\Approvals\Interfaces\Approvable;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Models\ApprovalStage;
use App\Modules\Approvals\Tools\GetEntityComments;
use App\Modules\Approvals\Tools\GetEntityDetails;
use App\Modules\Bot\Models\Bot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(10)]
class ApprovalEvaluationAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    private bool $hasComments;

    public function __construct(
        private Model&Approvable $entity,
        private ApprovalStage $stage,
        private ApprovalProcess $process,
        private ?Bot $bot = null,
    ) {
        $queueItem = $this->entity->toApprovalQueueItem();
        $this->hasComments = $queueItem->comments_url !== null;
    }

    public function instructions(): Stringable|string
    {
        $pipelineName = $this->process->pipeline?->name ?? 'Nieznany';
        $stageName = $this->stage->name;

        $hasCriteria = filled($this->stage->description);
        $stageCriteria = $hasCriteria
            ? $this->stage->description
            : 'Brak jawnych kryteriów dla tego etapu.';

        $noCriteriaRule = $hasCriteria
            ? ''
            : "\n        - Ten etap NIE MA jawnych kryteriów. W takiej sytuacji domyślnie ZATWIERDŹ. "
                . 'Odrzuć wyłącznie wtedy, gdy przesłana praca w konkretny sposób NIE realizuje zadania. '
                . 'Nie wymyślaj własnych wymagań jakościowych, formalnych ani strukturalnych.';

        $commentsInstruction = $this->hasComments
            ? "2. Użyj narzędzia GetEntityComments aby zapoznać się z komentarzami i dyskusją (przeglądaj kolejne strony kursorem jeśli has_more=true).\n        3. Oceń WYKONANĄ PRACĘ pod kątem kryteriów etapu."
            : '2. Oceń WYKONANĄ PRACĘ pod kątem kryteriów etapu.';

        $personaSection = $this->personaSection();

        return <<<INSTRUCTIONS
        Jesteś recenzentem AI w procesie zatwierdzania "{$pipelineName}".
        Aktualny etap: "{$stageName}".{$personaSection}

        CO OCENIASZ:
        Oceniasz, czy PRZESŁANA PRACA — czyli odpowiedzi wypełnione przez użytkownika oraz jego komentarze —
        REALIZUJE kryteria tego etapu. Oceniasz WYKONANIE i REZULTAT, a nie projekt zadania.

        CZEGO NIE OCENIASZ (to kwestie autorskie, nie kryteria akceptacji — IGNORUJ je całkowicie):
        - struktury ani szablonu formularza (pola `questions` to wyłącznie KONTEKST),
        - tego, czy pola są oznaczone jako wymagane, ani sposobu zaprojektowania formularza,
        - tego, czy pole `description` samego zadania jest wypełnione.
        Nie sugeruj zmian w budowie formularza ani definicji zadania. Oceniaj wyłącznie ODPOWIEDZI
        przesłane przez użytkownika (pole `answers`) oraz jego komentarze, w odniesieniu do kryteriów etapu.

        KRYTERIA ETAPU:
        {$stageCriteria}

        INSTRUKCJE:
        1. Użyj narzędzia GetEntityDetails aby pobrać szczegóły elementu oraz odpowiedzi przesłane przez użytkownika.
        {$commentsInstruction}

        ZASADY OCENY:
        - Zatwierdź ("approved"), jeśli przesłana praca spełnia kryteria etapu lub brak konkretnych powodów do odrzucenia.
        - Odrzuć ("rejected") wyłącznie z konkretnymi powodami związanymi z WYKONANĄ PRACĄ i kryteriami etapu.
        - Jeśli element nie ma formularza lub odpowiedzi — oceniaj na podstawie dostępnych informacji o wykonaniu zadania.{$noCriteriaRule}
        - Bądź obiektywny i konstruktywny.
        INSTRUCTIONS;
    }

    /**
     * Persona block for a NAMED BOT approver. Empty for a generic AI stage, so the
     * generic evaluation behavior is byte-for-byte unchanged. The persona colors the
     * verdict and the reasons (the lifecycle, tools and schema stay identical).
     */
    private function personaSection(): string
    {
        if ($this->bot === null) {
            return '';
        }

        $persona = $this->bot->persona ?: 'Brak zdefiniowanej persony.';
        $style = $this->bot->style ?: 'Brak zdefiniowanego stylu.';
        $dictionary = $this->joinList($this->bot->dictionary);
        $phrases = $this->joinList($this->bot->phrases);
        $prohibitions = $this->joinList($this->bot->prohibitions);

        return <<<BOTPERSONA


        OCENIASZ JAKO BOT "{$this->bot->name}". Zachowaj jego charakter w werdykcie i uzasadnieniu.
        PERSONA:
        {$persona}
        STYL:
        {$style}
        SŁOWNIK (preferowane terminy): {$dictionary}
        FRAZY: {$phrases}
        ZAKAZY: {$prohibitions}
        BOTPERSONA;
    }

    private function joinList(?array $values): string
    {
        return empty($values) ? 'brak' : implode(', ', $values);
    }

    /** @return iterable<\Laravel\Ai\Contracts\Tool> */
    public function tools(): iterable
    {
        $tools = [new GetEntityDetails($this->entity, $this->stage)];

        if ($this->hasComments) {
            $tools[] = new GetEntityComments($this->entity);
        }

        return $tools;
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'decision' => $schema->string()
                ->enum(['approved', 'rejected'])
                ->description('Decyzja: approved lub rejected')
                ->required(),
            'note' => $schema->string()
                ->description('Uzasadnienie decyzji. Przy odrzuceniu — konkretne powody i sugestie poprawy.')
                ->required(),
        ];
    }
}
