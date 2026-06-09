<?php

namespace App\Modules\Approvals\Agents;

use App\Modules\Approvals\Interfaces\Approvable;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Models\ApprovalStage;
use App\Modules\Approvals\Tools\GetEntityComments;
use App\Modules\Approvals\Tools\GetEntityDetails;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(10)]
class ApprovalEvaluationAgent implements Agent, HasTools, HasStructuredOutput
{
    use Promptable;

    private bool $hasComments;

    public function __construct(
        private Model&Approvable $entity,
        private ApprovalStage $stage,
        private ApprovalProcess $process,
    ) {
        $queueItem = $this->entity->toApprovalQueueItem();
        $this->hasComments = $queueItem->comments_url !== null;
    }

    public function instructions(): Stringable|string
    {
        $pipelineName = $this->process->pipeline?->name ?? 'Nieznany';
        $stageName = $this->stage->name;
        $stageCriteria = $this->stage->description ?? 'Brak szczegółowych kryteriów — oceń ogólną kompletność i jakość.';

        $commentsInstruction = $this->hasComments
            ? "2. Użyj narzędzia GetEntityComments aby zapoznać się z komentarzami i dyskusją (przeglądaj kolejne strony kursorem jeśli has_more=true).\n3. Oceń element pod kątem kryteriów etapu."
            : "2. Oceń element pod kątem kryteriów etapu.";

        return <<<INSTRUCTIONS
        Jesteś recenzentem AI w procesie zatwierdzania "{$pipelineName}".
        Aktualny etap: "{$stageName}".

        KRYTERIA ETAPU:
        {$stageCriteria}

        INSTRUKCJE:
        1. Użyj narzędzia GetEntityDetails aby pobrać szczegóły elementu do zatwierdzenia.
        {$commentsInstruction}

        ZASADY OCENY:
        - Zatwierdź ("approved") jeśli element spełnia kryteria etapu lub brak powodów do odrzucenia.
        - Odrzuć ("rejected") jeśli element wyraźnie nie spełnia kryteriów — podaj konkretne powody.
        - Jeśli element nie ma formularza lub innych danych — oceniaj na podstawie dostępnych informacji.
        - Bądź obiektywny i konstruktywny.
        INSTRUCTIONS;
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
