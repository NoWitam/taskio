<?php

namespace App\Modules\Changelog\Managers;

use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Interfaces\HasChangelog;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractTracker
{
    protected bool $trackOriginalInUpdating = false;
    protected bool $manualOnly = false;
    protected ?ChangelogEvent $event = null;
    protected array $savedOriginals = [];
    protected ?string $translationKey = null;

    public function __construct(
        protected string $field
    ) {}   

    public static function make(...$args): static
    {
        return new static(...$args);
    }

    public function getField(): string
    {
        return $this->field;
    }

    /**
     * Włącz zapisywanie oryginalnych wartości podczas updating
     */
    public function trackOriginalInUpdating(): static
    {
        $this->trackOriginalInUpdating = true;

        return $this;
    }

    /**
     * Sprawdź czy tracker ma zapisywać oryginalne wartości
     */
    public function shouldTrackOriginalInUpdating(): bool
    {
        return $this->trackOriginalInUpdating;
    }

    /**
     * Oznacz tracker jako tylko dla manual() - nie będzie auto-trackowany w updated event
     */
    public function manualOnly(): static
    {
        $this->manualOnly = true;
        return $this;
    }

    /**
     * Sprawdź czy tracker jest tylko dla manual()
     */
    public function isManualOnly(): bool
    {
        return $this->manualOnly;
    }

    /**
     * Ustaw niestandardowy klucz tłumaczenia dla pola
     */
    public function withTranslationKey(string $key): static
    {
        $this->translationKey = $key;
        return $this;
    }

    /**
     * Pobierz klucz tłumaczenia - zwraca custom lub domyślny
     */
    public function getTranslationKey(): string
    {
        return $this->translationKey ?? "changelog.fields.{$this->field}";
    }

    /**
     * Ustaw event dla którego ten tracker ma działać
     */
    public function forEvent(ChangelogEvent $event): static
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Pobierz event dla którego działa ten tracker
     */
    public function getEvent(): ?ChangelogEvent
    {
        return $this->event;
    }

    /**
     * Zapisz oryginalne wartości (wywoływane w updating)
     */
    public function saveOriginals(Model&HasChangelog $model): void
    {
        $this->savedOriginals = [];
    }

    /**
     * Pobierz zapisane oryginalne wartości
     */
    public function getSavedOriginals(): array
    {
        return $this->savedOriginals;
    }

    abstract public function prepare(Model&HasChangelog $model): ?array;
}
