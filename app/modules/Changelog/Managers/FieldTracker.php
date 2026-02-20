<?php

namespace App\Modules\Changelog\Managers;

use App\Modules\Changelog\Interfaces\HasChangelog;
use Closure;
use Illuminate\Database\Eloquent\Model;

class FieldTracker extends AbstractTracker
{
    protected bool $withComparision = false;
    protected ?Closure $map = null;
    protected ?string $component = null;

    /**
     * Zapisz oryginalną wartość pola
     */
    public function saveOriginals(Model&HasChangelog $model): void
    {
        $this->savedOriginals = [
            'original' => $model->getOriginal($this->field),
        ];
    }

    public function withComparision(): static
    {
        $this->withComparision = true;

        return $this;
    }

    public function withMap(Closure $map): self
    {
        $this->map = $map;

        return $this;
    }

    public function asComponent(string $component): self
    {
        $this->component = $component;

        return $this;
    }

    public function prepare(Model&HasChangelog $model): ?array
    {
        // Użyj zapisanej oryginalnej wartości jeśli dostępna, w przeciwnym razie z modelu
        $original = $this->savedOriginals['original'] ?? $model->getOriginal($this->field);
        $dirty = $model->{$this->field};

        if($dirty == $original) {
            return null;
        }

        $map = $this->map;

        $result = [
            'type' => 'field',
            'component' => $this->component,
            'before' => $map == null ? $original : $map($original, $model),
            'after' => $map == null ? $dirty : $map($dirty, $model),
        ];

        // Generuj word-level diff jeśli włączony comparision
        if ($this->withComparision && is_string($original) && is_string($dirty)) {
            $result['comparision'] = $this->generateWordDiff($original, $dirty);
        }

        return $result;
    }

    /**
     * Generuje word-level diff między dwoma tekstami
     * 
     * @return array<array{type: string, text: string}>
     */
    protected function generateWordDiff(string $old, string $new): array
    {
        // Podziel na słowa (separatory: spacje, nowe linie, tabulatory)
        $oldWords = preg_split('/(\s+)/', $old, -1, PREG_SPLIT_DELIM_CAPTURE);
        $newWords = preg_split('/(\s+)/', $new, -1, PREG_SPLIT_DELIM_CAPTURE);

        $diff = [];
        $oldIndex = 0;
        $newIndex = 0;

        while ($oldIndex < count($oldWords) || $newIndex < count($newWords)) {
            // Jeśli oba słowa są takie same
            if ($oldIndex < count($oldWords) && $newIndex < count($newWords) && $oldWords[$oldIndex] === $newWords[$newIndex]) {
                $diff[] = ['type' => 'unchanged', 'text' => $oldWords[$oldIndex]];
                $oldIndex++;
                $newIndex++;
            }
            // Jeśli są różne, sprawdź czy słowo z new występuje dalej w old
            else if ($oldIndex < count($oldWords) && $newIndex < count($newWords)) {
                // Szukaj następnego wspólnego słowa
                $foundMatch = false;
                for ($i = $oldIndex + 1; $i < min($oldIndex + 5, count($oldWords)); $i++) {
                    if ($oldWords[$i] === $newWords[$newIndex]) {
                        // Znaleziono match - wszystko między to deleted
                        for ($j = $oldIndex; $j < $i; $j++) {
                            $diff[] = ['type' => 'removed', 'text' => $oldWords[$j]];
                        }
                        $oldIndex = $i;
                        $foundMatch = true;
                        break;
                    }
                }
                
                if (!$foundMatch) {
                    // Nie znaleziono matcha w old, sprawdź w new
                    for ($i = $newIndex + 1; $i < min($newIndex + 5, count($newWords)); $i++) {
                        if ($newWords[$i] === $oldWords[$oldIndex]) {
                            // Znaleziono match - wszystko między to added
                            for ($j = $newIndex; $j < $i; $j++) {
                                $diff[] = ['type' => 'added', 'text' => $newWords[$j]];
                            }
                            $newIndex = $i;
                            $foundMatch = true;
                            break;
                        }
                    }
                }

                // Jeśli nadal nie znaleziono, potraktuj jako removed + added
                if (!$foundMatch) {
                    $diff[] = ['type' => 'removed', 'text' => $oldWords[$oldIndex]];
                    $oldIndex++;
                    if ($newIndex < count($newWords)) {
                        $diff[] = ['type' => 'added', 'text' => $newWords[$newIndex]];
                        $newIndex++;
                    }
                }
            }
            // Tylko old pozostało
            else if ($oldIndex < count($oldWords)) {
                $diff[] = ['type' => 'removed', 'text' => $oldWords[$oldIndex]];
                $oldIndex++;
            }
            // Tylko new pozostało
            else if ($newIndex < count($newWords)) {
                $diff[] = ['type' => 'added', 'text' => $newWords[$newIndex]];
                $newIndex++;
            }
        }

        return $diff;
    }
}
