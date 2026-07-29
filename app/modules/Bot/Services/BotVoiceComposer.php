<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Models\Bot;

/**
 * Composes a bot's text-module material (persona / style / dictionary / phrases / prohibitions) into ONE
 * opaque VOICE directive string (R2 sub-stage 3) — the exact material {@see \App\Modules\Bot\Agents\BotTaskExecutionAgent::instructions}
 * folds into its instruction block, MIRRORED here (the same {@see Bot::dictionaryEntries} / {@see Bot::phraseEntries}
 * accessors + join style) rather than shared, since the two consumers frame it differently.
 *
 * The output is a single directive the Generator treats as an OPAQUE style string: it is SNAPSHOTTED into
 * the session's delegation overlay and dropped into the ai-text agent's system instruction IN PLACE OF the
 * fixed persona tone line. It carries only authored bot config (trusted) — never runtime user content — and
 * is NEVER logged.
 */
class BotVoiceComposer
{
    public function compose(Bot $bot): string
    {
        $persona = trim((string) $bot->persona);
        $style = trim((string) $bot->style);
        $dictionary = $this->renderDictionary($bot->dictionaryEntries());
        $phrases = $this->renderPhrases($bot->phraseEntries());
        $prohibitions = $this->joinList($bot->prohibitions);

        $personaLine = $persona === '' ? 'Nie zdefiniowano persony.' : $persona;
        $styleLine = $style === '' ? 'Nie zdefiniowano stylu.' : $style;

        return <<<VOICE
        Write in the VOICE of this author. Match its persona, style, vocabulary, and phrasing exactly, and
        honor its prohibitions.

        PERSONA:
        {$personaLine}

        STYL:
        {$styleLine}

        SŁOWNIK (preferowane terminy): {$dictionary}
        FRAZY (używaj gdy pasują): {$phrases}
        ZAKAZY (nigdy nie używaj / nie rób): {$prohibitions}
        VOICE;
    }

    private function joinList(?array $values): string
    {
        return empty($values) ? 'brak' : implode(', ', $values);
    }

    /**
     * Render dictionary entries as `term — meaning` lines (MIRRORS BotTaskExecutionAgent).
     *
     * @param  array<int, array{term: string, meaning: string}>  $entries
     */
    private function renderDictionary(array $entries): string
    {
        if ($entries === []) {
            return 'brak';
        }

        return implode('; ', array_map(
            fn ($e) => filled($e['meaning']) ? "{$e['term']} — {$e['meaning']}" : $e['term'],
            $entries,
        ));
    }

    /**
     * Render phrase entries as `phrase (kontekst: …)` lines (MIRRORS BotTaskExecutionAgent).
     *
     * @param  array<int, array{phrase: string, context: string|null}>  $entries
     */
    private function renderPhrases(array $entries): string
    {
        if ($entries === []) {
            return 'brak';
        }

        return implode('; ', array_map(
            fn ($e) => filled($e['context']) ? "{$e['phrase']} (kontekst: {$e['context']})" : $e['phrase'],
            $entries,
        ));
    }
}
