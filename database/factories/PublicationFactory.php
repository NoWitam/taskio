<?php

namespace Database\Factories;

use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publication>
 *
 * The DEFAULT state is a DRAFT on the dry-run destination — the state every publication really starts
 * in, on the one destination that publishes nothing. A factory whose default was `scheduled` would let
 * half the suite skip the arming step and never notice that it had.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE STATES BELOW SET COLUMNS DIRECTLY, AND THAT IS THE ONE PLACE IT IS ALLOWED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Everywhere else in this codebase, `publications.status` is written only by `PublicationManager` —
 * asserted over the module's file bytes by {@see \Tests\Feature\PublishingStateMachineTest}, which
 * scans app/modules/Publishing and does not reach here.
 *
 * A fixture legitimately needs to START in a state rather than walk to it: a test about reconciliation
 * should not have to stage a crash to obtain a `needs_reconcile` row, and one about `blocked` should not
 * have to break a connection that does not exist until B2.
 *
 * The cost is real and worth naming: a state set here has NOT been through the transition table, so a
 * fixture can hold a combination the machine would never produce — `published` with no `remote_id`, say.
 * The states below therefore set the COMPANION COLUMNS the machine would have set, so a fixture is a
 * plausible row rather than merely a status string.
 */
class PublicationFactory extends Factory
{
    protected $model = Publication::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            // The destination that publishes nothing. A factory defaulting to a real platform would
            // make an accidentally-wired test a real API call the day B2 lands.
            'platform' => PublishingPlatform::DRY_RUN,
            'platform_connection_id' => null,
            'status' => PublicationStatus::DRAFT,
            'scheduled_at' => null,
            'published_at' => null,
            'media' => [],
            'options' => [],
            'remote_id' => null,
            'remote_draft_id' => null,
            'remote_url' => null,
            'attempts' => 0,
        ];
    }

    /** A publication on a named destination. */
    public function on(PublishingPlatform $platform): static
    {
        return $this->state(fn (): array => ['platform' => $platform]);
    }

    /** A DRAFT that already carries a moment — created with a time, not yet armed. */
    public function withScheduledAt(string $scheduledAt): static
    {
        return $this->state(fn (): array => [
            'scheduled_at' => CarbonImmutable::parse($scheduledAt, 'UTC'),
        ]);
    }

    /** ARMED for a moment. The state the calendar draws and the due-sweep claims. */
    public function scheduled(string $scheduledAt = '2026-09-10 09:00:00'): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::SCHEDULED,
            'scheduled_at' => CarbonImmutable::parse($scheduledAt, 'UTC'),
        ]);
    }

    /** CLAIMED and in flight. Carries an attempt, as the Manager's claim would have left it. */
    public function publishing(string $scheduledAt = '2026-09-10 09:00:00'): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::PUBLISHING,
            'scheduled_at' => CarbonImmutable::parse($scheduledAt, 'UTC'),
            'attempts' => 1,
            'last_attempt_at' => CarbonImmutable::parse($scheduledAt, 'UTC'),
        ]);
    }

    /** OUT IN THE WORLD — with the remote identity a published row always has. */
    public function published(string $scheduledAt = '2026-09-10 09:00:00'): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::PUBLISHED,
            'scheduled_at' => CarbonImmutable::parse($scheduledAt, 'UTC'),
            'published_at' => CarbonImmutable::parse($scheduledAt, 'UTC'),
            'attempts' => 1,
            'remote_id' => 'dryrun_' . $this->faker->lexify('????????????????????'),
        ]);
    }

    /** DEFINITELY did not happen. Carries a code, because a failed row without one explains nothing. */
    public function failed(string $failureCode = 'title_missing'): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::FAILED,
            'scheduled_at' => CarbonImmutable::parse('2026-09-10 09:00:00', 'UTC'),
            'attempts' => 1,
            'failure_code' => $failureCode,
        ]);
    }

    /**
     * WE DO NOT KNOW.
     *
     * It carries a `remote_draft_id` by default, because that is what the real path leaves behind: the
     * phase-1 handle survives precisely so a reconciliation has something to ask about. A fixture
     * without one would quietly test the easier half of the problem.
     */
    public function needsReconcile(): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::NEEDS_RECONCILE,
            'scheduled_at' => CarbonImmutable::parse('2026-09-10 09:00:00', 'UTC'),
            'attempts' => 1,
            'remote_draft_id' => 'dryrun_draft_' . $this->faker->lexify('????????????????????????'),
            'failure_code' => 'publish_outcome_unknown',
        ]);
    }

    /**
     * HELD because its connection is not usable.
     *
     * `scheduled_at` is KEPT, exactly as `PublicationManager::block()` keeps it: the publication still
     * wants to go out when somebody said, and throwing the schedule away would make fixing the
     * connection insufficient to recover.
     */
    public function blocked(string $failureCode = 'connection_unusable'): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::BLOCKED,
            'scheduled_at' => CarbonImmutable::parse('2026-09-10 09:00:00', 'UTC'),
            'failure_code' => $failureCode,
        ]);
    }

    /**
     * With media attached — ORDERED Disk file ids, stored verbatim and never dereferenced.
     *
     * @param  array<int, string>  $fileIds
     */
    public function withMedia(array $fileIds): static
    {
        return $this->state(fn (): array => ['media' => array_values($fileIds)]);
    }
}
