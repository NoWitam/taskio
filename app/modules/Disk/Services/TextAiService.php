<?php

namespace App\Modules\Disk\Services;

use App\Modules\Disk\Agents\DiskTextAiAgent;

/**
 * SYNC AI TEXT edit for the Disk preview editor: apply a natural-language instruction to a text
 * file's content and return the edited text. Unlike the image edit (queued — the provider call runs
 * tens of seconds), a gpt-4o text edit is fast, so it runs INLINE in the request.
 *
 * This is the thin seam over laravel/ai's text generation (mirrors WorkflowAiTextService's call
 * shape). It deliberately does NOT swallow failures: a provider / transport error propagates so the
 * controller can collapse it to a localized 502 — the editor must know the edit did not happen,
 * where a workflow ai-text directive fails closed to ''.
 */
class TextAiService
{
    public function edit(string $content, string $instruction): string
    {
        $response = (new DiskTextAiAgent)->prompt(
            prompt: $this->composePrompt($content, $instruction),
            provider: config('ai.provider'),
            model: config('ai.model'),
            timeout: (int) config('ai.text_timeout'),
        );

        return trim((string) ($response->text ?? ''));
    }

    /**
     * Frame the instruction and the content as two clearly-delimited blocks. The agent's system
     * instruction pins the output contract; this only structures the user message so the model can
     * tell the command apart from the content it must transform.
     */
    private function composePrompt(string $content, string $instruction): string
    {
        return <<<PROMPT
        INSTRUCTION:
        {$instruction}

        TEXT TO EDIT:
        {$content}
        PROMPT;
    }
}
