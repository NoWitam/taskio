<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Generator\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        // A minimal, valid `post` template: one text slot referenced by the post body.
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'description' => null,
            'content_type' => 'post',
            'slots' => [
                ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
            ],
            'content' => [
                'body' => ['markdown' => 'Write a post about ' . self::directive('slots.topic') . '.'],
            ],
            'creator_id' => User::factory(),
        ];
    }

    /** A template of an explicit content type with the given per-part content + slots. */
    public function definitionOf(string $contentType, array $content, array $slots): static
    {
        return $this->state(fn (): array => [
            'content_type' => $contentType,
            'content' => $content,
            'slots' => $slots,
        ]);
    }

    /** A template whose declared slots are exactly $slots (content_type / content default to the base). */
    public function slots(array $slots): static
    {
        return $this->state(fn (): array => ['slots' => $slots]);
    }

    /** A template whose per-part content is exactly $content (content_type defaults to the base). */
    public function content(array $content): static
    {
        return $this->state(fn (): array => ['content' => $content]);
    }

    /**
     * A `post_with_image` template: a text body + a file slot the image plan pulls from (a from_slot base
     * with one pixel + one ai_edit filter — the part-aware shape the render/validation tests drive).
     */
    public function postWithImage(): static
    {
        return $this->state(fn (): array => [
            'content_type' => 'post_with_image',
            'slots' => [
                ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
                ['name' => 'photo', 'description' => 'Hero image', 'descriptor' => self::fileDescriptor()],
            ],
            'content' => [
                'body' => ['markdown' => 'Write about ' . self::directive('slots.topic') . '.'],
                'image' => [
                    'base' => ['kind' => 'from_slot', 'slot' => 'photo'],
                    'filters' => [
                        ['kind' => 'pixel', 'op' => 'grayscale'],
                        ['kind' => 'ai_edit', 'prompt' => 'Make it feel like ' . self::directive('slots.topic') . '.'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * A `video_script` template in the REWORKED shape (ADR-0035): a `shot_list` part authored as a creative
     * BRIEF (one structured AI call at run time → hook / shots / cta) + an optional `storyboard` part (an
     * authored style prompt + filter chain the executor applies to one AI image per shot). The old
     * `script` / `scene_plan` kinds are back-compat-only (existing snapshots still render) and are NOT
     * authored here — building the legacy shape now would produce a template the API can no longer re-save.
     */
    public function videoScript(): static
    {
        return $this->state(fn (): array => [
            'content_type' => 'video_script',
            'slots' => [
                ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
            ],
            'content' => [
                'shot_list' => ['brief' => ['markdown' => 'A short vertical video about ' . self::directive('slots.topic') . '.']],
                'storyboard' => [
                    'style' => ['markdown' => 'Flat vector illustration of ' . self::directive('slots.topic') . '.'],
                    'filters' => [['kind' => 'pixel', 'op' => 'sepia']],
                ],
            ],
        ]);
    }

    /** The fixed file COMPOSITE descriptor a file SLOT declares ({base:file, fields:[id,name,type,size,url]}). */
    public static function fileDescriptor(): array
    {
        $scalar = fn (string $base): array => ['base' => $base, 'nullable' => false, 'array' => false];

        return [
            'base' => 'file',
            'nullable' => false,
            'array' => false,
            'fields' => [
                ['key' => 'id', 'label' => 'id', 'descriptor' => $scalar('text')],
                ['key' => 'name', 'label' => 'name', 'descriptor' => $scalar('text')],
                ['key' => 'type', 'label' => 'type', 'descriptor' => $scalar('text')],
                ['key' => 'size', 'label' => 'size', 'descriptor' => $scalar('number')],
                ['key' => 'url', 'label' => 'url', 'descriptor' => $scalar('text')],
            ],
        ];
    }

    /** The `@[variable]("<payload>")` byte-format the resolver + the write-validator read. */
    public static function directive(string $id, array $pipeline = []): string
    {
        $data = ['id' => $id, 'type' => 'text'];

        if ($pipeline !== []) {
            $data['pipeline'] = $pipeline;
        }

        $json = json_encode(['v' => 1, 'data' => $data]);

        return '@[variable]("' . str_replace('"', '\\"', $json) . '")';
    }
}
