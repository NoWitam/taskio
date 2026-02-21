<?php

namespace App\Modules\Changelog\Http\Resources;

use App\Modules\Changelog\Interfaces\HasChangelog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangelogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'event_description' => $this->event->getDescription(),
            'details' => $this->translateDetails($this->details ?? []),
            'causer' => $this->causer ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
                'email' => $this->causer->email,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Tłumacz klucze pól w details
     */
    protected function translateDetails(array $details): array
    {
        $translated = [];

        foreach ($details as $field => $data) {
            // Pobierz translation_key z managera modelu
            $translationKey = $this->getTranslationKeyFromManager($field);
            
            // Pobierz tłumaczenie
            $fieldLabel = __($translationKey);
            
            // Jeśli nie znaleziono tłumaczenia, użyj czytelnej wersji klucza
            if ($fieldLabel === $translationKey) {
                $fieldLabel = ucfirst(str_replace('_', ' ', $field));
            }

            // Dodaj informacje o polu do danych
            $translated[$field] = array_merge([
                'field' => $field,
                'field_label' => $fieldLabel,
            ], $data);
        }

        return $translated;
    }

    /**
     * Pobierz klucz tłumaczenia z managera modelu
     */
    protected function getTranslationKeyFromManager(string $field): string
    {
        // Domyślny klucz tłumaczenia
        $defaultKey = "changelog.fields.{$field}";

        // Sprawdź czy subject jest załadowany i implementuje HasChangelog
        if (!$this->relationLoaded('subject') || !($this->subject instanceof HasChangelog)) {
            return $defaultKey;
        }

        try {
            // Pobierz manager z modelu
            $manager = $this->subject->getChangelogManager();
            
            // Pobierz tracker dla danego pola
            $tracker = $manager->getTracker($field);
            
            // Jeśli tracker istnieje, zwróć jego translation key
            if ($tracker) {
                return $tracker->getTranslationKey();
            }
        } catch (\Exception $e) {
            // W przypadku błędu zwróć domyślny klucz
            return $defaultKey;
        }

        return $defaultKey;
    }
}
