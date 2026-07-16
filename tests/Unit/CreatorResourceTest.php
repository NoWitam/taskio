<?php

namespace Tests\Unit;

use App\Http\Resources\CreatorResource;
use App\Modules\Bot\Models\Bot;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\TestCase;

/**
 * Phase 4: the presentation edges of the polymorphic creator that the end-to-end
 * CreatorAttributionTest cannot reach through a single show endpoint (which always
 * eager-loads a User/WorkflowRun creator). Every production resource embeds the
 * creator VERBATIM as `CreatorResource::make($this->whenLoaded('creator'))`, so these
 * cases render through that exact pattern and assert the serialized envelope, not the
 * resource internals:
 *   - creator relation NOT loaded  -> the `creator` key is OMITTED entirely;
 *   - creator loaded but NULL      -> the key is present and serializes to `null`;
 *   - a Bot creator                -> { type: 'bot', id, name, avatar } (never a user shape).
 *
 * The User and WorkflowRun shapes are already pinned end-to-end in CreatorAttributionTest.
 */
class CreatorResourceTest extends TestCase
{
    /**
     * Serialize a host model through the canonical production pattern and return the
     * resource body (the payload under the top-level `data` wrapper).
     *
     * @return array<string, mixed>
     */
    private function render(Task $host): array
    {
        $parent = new class($host) extends JsonResource
        {
            /** @return array<string, mixed> */
            public function toArray($request): array
            {
                return ['creator' => CreatorResource::make($this->whenLoaded('creator'))];
            }
        };

        return $parent->response(Request::create('/'))->getData(true)['data'];
    }

    public function test_a_not_loaded_creator_omits_the_key(): void
    {
        // A fresh host with no eager-loaded creator relation.
        $body = $this->render(new Task);

        $this->assertArrayNotHasKey('creator', $body);
    }

    public function test_a_loaded_but_null_creator_serializes_to_null(): void
    {
        // A legacy / unattributed row: the relation IS loaded, but resolves to nobody.
        $host = new Task;
        $host->setRelation('creator', null);

        $body = $this->render($host);

        $this->assertArrayHasKey('creator', $body);
        $this->assertNull($body['creator']);
    }

    public function test_a_bot_creator_serializes_the_bot_shape(): void
    {
        $bot = new Bot;
        $bot->id = '11111111-1111-1111-1111-111111111111';
        $bot->name = 'Nightly Helper';

        $host = new Task;
        $host->setRelation('creator', $bot);

        $creator = $this->render($host)['creator'];

        $this->assertSame([
            'type' => 'bot',
            'id' => $bot->id,
            'name' => 'Nightly Helper',
            'avatar' => null,
        ], $creator);

        // It must never be mistaken for a human: no email key, discriminator is not 'user'.
        $this->assertArrayNotHasKey('email', $creator);
        $this->assertNotSame('user', $creator['type']);
    }
}
