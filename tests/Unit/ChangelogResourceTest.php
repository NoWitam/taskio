<?php

namespace Tests\Unit;

use App\Modules\Changelog\Enums\ChangelogEvent;
use App\Modules\Changelog\Http\Resources\ChangelogResource;
use App\Modules\Changelog\Models\Changelog;
use Illuminate\Http\Request;
use Tests\TestCase;

class ChangelogResourceTest extends TestCase
{
    /**
     * Regression: custom events (e.g. Approvals) store scalar detail values
     * instead of the array change-descriptors the trackers emit. The resource
     * used to call array_merge() on those strings and threw a TypeError, 500ing
     * the whole `GET /api/{model}/{id}/changelog` endpoint.
     */
    public function test_scalar_detail_values_are_wrapped_without_error(): void
    {
        $changelog = new Changelog([
            'event' => ChangelogEvent::APPROVAL_STARTED,
            'details' => ['pipeline' => 'Podstawowa akceptacja', 'stage' => 'Akceptacja wstępna'],
        ]);

        $data = (new ChangelogResource($changelog))->toArray(Request::create('/'));

        $this->assertSame('Podstawowa akceptacja', $data['details']['pipeline']['value']);
        $this->assertSame('pipeline', $data['details']['pipeline']['field']);
        $this->assertArrayHasKey('field_label', $data['details']['pipeline']);
        $this->assertSame('Akceptacja wstępna', $data['details']['stage']['value']);
    }

    /**
     * Array-shaped tracker details (status change, field diff, …) keep their
     * descriptor and are still merged with the field metadata envelope.
     */
    public function test_array_detail_values_keep_descriptor_shape(): void
    {
        $changelog = new Changelog([
            'event' => ChangelogEvent::CHANGE_STATUS,
            'details' => [
                'status' => [
                    'type' => 'status_change',
                    'before' => ['label' => 'Do zrobienia'],
                    'after' => ['label' => 'W trakcie'],
                ],
            ],
        ]);

        $data = (new ChangelogResource($changelog))->toArray(Request::create('/'));

        $this->assertSame('status_change', $data['details']['status']['type']);
        $this->assertSame('status', $data['details']['status']['field']);
        $this->assertSame('Do zrobienia', $data['details']['status']['before']['label']);
        $this->assertArrayNotHasKey('value', $data['details']['status']);
    }
}
