<?php

namespace Database\Factories;

use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeBinding;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class KnowledgeBindingFactory extends Factory
{
    protected $model = KnowledgeBinding::class;

    /**
     * The consumer defaults to a made-up alias + uuid: a binding is addressed by PRIMITIVES, so a factory
     * that needed a real consumer model would drag the whole dependency this module exists without. Tests
     * that bind a real bot pass {@see self::for()}.
     */
    public function definition(): array
    {
        return [
            'bindable_type' => 'bot',
            'bindable_id' => (string) Str::uuid(),
            'knowledge_base_id' => KnowledgeBase::factory(),
            'mode' => KnowledgeBindingMode::AUTO,
        ];
    }

    public function forBindable(string $type, string $id): static
    {
        return $this->state(fn () => ['bindable_type' => $type, 'bindable_id' => $id]);
    }

    public function mode(KnowledgeBindingMode $mode): static
    {
        return $this->state(fn () => ['mode' => $mode]);
    }
}
