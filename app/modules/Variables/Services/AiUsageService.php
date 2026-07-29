<?php

namespace App\Modules\Variables\Services;

use App\Modules\Variables\Models\AiUsageEvent;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * The read side of the AI cost meter: the CALENDAR-MONTH spend for the ACTIVE workspace (the gate's
 * budget check) plus the derived cap/remaining/warn figures and the per-channel / per-actor breakdowns a
 * usage UI reads. Since R2 sub-stage 4 the primary unit is DOLLARS (`estimated_cost`); tokens stay as a
 * secondary display.
 *
 * Every query rides {@see AiUsageEvent}'s WorkspaceScope, so a shared-database call is automatically
 * scoped to the current workspace and an own-database call runs on that tenant's connection — the sum is
 * always "this workspace, this month". {@see cap()} is PURE (it reads the already-loaded CENTRAL Workspace
 * model + config, NO query) so it is correct in own-database mode too (TenantContext holds the central row
 * even on a tenant connection). Per-actor NAMES are resolved at READ time through the morph map (never
 * stored) and only for the top-N actors surfaced.
 */
class AiUsageService
{
    /** How many actors the per-actor summary surfaces by $; the remainder folds into an "others" bucket. */
    private const ACTOR_TOP_N = 5;

    public function __construct(
        private TenantContext $tenant,
    ) {}

    /** Total tokens the active workspace has spent since the start of the current calendar month. */
    public function currentMonthTokens(): int
    {
        return (int) AiUsageEvent::query()
            ->where('created_at', '>=', $this->monthStart())
            ->sum('total_tokens');
    }

    /** Total ESTIMATED dollars the active workspace has spent since the start of the current month. */
    public function currentMonthCost(): float
    {
        return (float) AiUsageEvent::query()
            ->where('created_at', '>=', $this->monthStart())
            ->sum('estimated_cost');
    }

    /**
     * The effective monthly $ budget (Cap Option B), the SINGLE source of truth the gate routes through.
     * PURE: reads the already-loaded CENTRAL Workspace model (own-database safe — TenantContext holds the
     * central row even on a tenant connection) + config; no query. Semantics: a non-null override wins (a
     * positive value = this workspace's cap; 0.00 = explicit UNLIMITED → 0.0 → gate off); null inherits the
     * env default (itself 0.0 by default → gate off, byte-preserving). (Option-C platform ceiling would clamp
     * here in one line.)
     */
    public function cap(): float
    {
        $override = $this->tenant->workspace()?->ai_monthly_cost_cap;

        if ($override !== null) {
            return (float) $override;
        }

        return (float) config('ai.meter.monthly_cost_cap_default', 0);
    }

    /** Dollars left this month, or null when uncapped. Never negative. */
    public function remaining(): ?float
    {
        $cap = $this->cap();

        if ($cap <= 0) {
            return null;
        }

        return (float) max(0, round($cap - $this->currentMonthCost(), 4));
    }

    /** The fraction of the cap at which a UI should warn (e.g. 0.8 = 80%). */
    public function warnRatio(): float
    {
        return (float) config('ai.meter.warn_ratio', 0.8);
    }

    /**
     * The GATE predicate (R2 sub-stage 4): the effective cap is SET and this month's spend has REACHED it.
     * The SINGLE source of truth shared by the pre-run gate and the summary's `blocked` flag, so the server's
     * up-front refusal and the FE budget banner always AGREE. Byte-preserving: false whenever the cap is off
     * (`cap() <= 0` — the default), so an uncapped workspace is never blocked.
     */
    public function blocked(): bool
    {
        $cap = $this->cap();

        return $cap > 0 && $this->currentMonthCost() >= $cap;
    }

    /**
     * This month's spend grouped by CHANNEL, ordered by $ desc.
     *
     * @return array<int, array{channel: string, cost: float, tokens: int}>
     */
    public function currentMonthByChannel(): array
    {
        return AiUsageEvent::query()
            ->where('created_at', '>=', $this->monthStart())
            ->groupBy('channel')
            ->selectRaw('channel, SUM(estimated_cost) as cost, SUM(total_tokens) as tokens')
            ->get()
            ->map(fn ($row) => [
                'channel' => (string) $row->channel,
                'cost' => round((float) $row->cost, 4),
                'tokens' => (int) $row->tokens,
            ])
            ->sortByDesc('cost')
            ->values()
            ->all();
    }

    /**
     * This month's spend grouped by ACTOR (actor_type + actor_id), ordered by $ desc — the RAW aggregate,
     * no names (names are resolved in {@see summary()} only for the surfaced top-N). A null actor pair is
     * its own "unattributed" group.
     *
     * @return array<int, array{actor_type: ?string, actor_id: ?string, cost: float, tokens: int}>
     */
    public function currentMonthByActor(): array
    {
        return AiUsageEvent::query()
            ->where('created_at', '>=', $this->monthStart())
            ->groupBy('actor_type', 'actor_id')
            ->selectRaw('actor_type, actor_id, SUM(estimated_cost) as cost, SUM(total_tokens) as tokens')
            ->get()
            ->map(fn ($row) => [
                'actor_type' => $row->actor_type !== null ? (string) $row->actor_type : null,
                'actor_id' => $row->actor_id !== null ? (string) $row->actor_id : null,
                'cost' => round((float) $row->cost, 4),
                'tokens' => (int) $row->tokens,
            ])
            ->sortByDesc('cost')
            ->values()
            ->all();
    }

    /**
     * The whole usage SUMMARY the wire resource serializes (aggregates + read-resolved actor names ONLY —
     * never `meta`/prompts/slot content). $-first: `cost_*` are the primary figures, `tokens_used` is a
     * secondary display. `cap_source` tells the UI where the effective cap comes from; `blocked` /
     * `warn_reached` are the gate/warn signals; `per_actor` is the top-N by $ plus an "others" bucket.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $cap = $this->cap();
        $used = $this->currentMonthCost();
        $warnRatio = $this->warnRatio();
        $capped = $cap > 0;

        return [
            'currency' => 'USD',
            'estimated' => true,
            'cost_used' => round($used, 4),
            'cost_cap' => $cap,
            'cost_remaining' => $capped ? (float) max(0, round($cap - $used, 4)) : null,
            'cap_source' => $this->capSource(),
            'warn_ratio' => $warnRatio,
            'warn_reached' => $capped && $used >= $cap * $warnRatio,
            'blocked' => $this->blocked(),
            'period' => [
                'month' => $this->monthStart()->format('Y-m'),
                'resets_at' => $this->monthStart()->copy()->addMonth(),
            ],
            'tokens_used' => $this->currentMonthTokens(),
            'per_channel' => $this->currentMonthByChannel(),
            'per_actor' => $this->perActorSummary(),
        ];
    }

    /** Where the effective cap comes from — for the UI's cap-source hint. */
    private function capSource(): string
    {
        $override = $this->tenant->workspace()?->ai_monthly_cost_cap;

        if ($override !== null) {
            return (float) $override > 0 ? 'workspace' : 'unlimited';
        }

        return (float) config('ai.meter.monthly_cost_cap_default', 0) > 0 ? 'default' : 'unlimited';
    }

    /**
     * The top-N actors by $ (names resolved at read time via the morph map) + an aggregated "others"
     * bucket for the remainder. The top-N pairs are hydrated GENERICALLY (no sibling-module class named —
     * the Variables layer stays one-way), User un-scoped so a departed member still resolves.
     *
     * @return array<int, array{actor_type: ?string, actor_id: ?string, display_name: ?string, icon: ?string, cost: float, tokens: int}>
     */
    private function perActorSummary(): array
    {
        $all = $this->currentMonthByActor();
        $top = array_slice($all, 0, self::ACTOR_TOP_N);
        $rest = array_slice($all, self::ACTOR_TOP_N);

        $names = $this->resolveActorNames($top);

        $out = array_map(function (array $row) use ($names) {
            $key = ($row['actor_type'] ?? '') . '|' . ($row['actor_id'] ?? '');

            return [
                'actor_type' => $row['actor_type'],
                'actor_id' => $row['actor_id'],
                'display_name' => $names[$key]['display_name'] ?? null,
                'icon' => $names[$key]['icon'] ?? null,
                'cost' => $row['cost'],
                'tokens' => $row['tokens'],
            ];
        }, $top);

        if ($rest !== []) {
            $out[] = [
                'actor_type' => 'others',
                'actor_id' => null,
                'display_name' => null,
                'icon' => null,
                'cost' => round(array_sum(array_column($rest, 'cost')), 4),
                'tokens' => (int) array_sum(array_column($rest, 'tokens')),
            ];
        }

        return $out;
    }

    /**
     * Resolve display name/icon for a set of actor rows GENERICALLY through the morph map — group the ids
     * by morph type, look up the concrete model with {@see Relation::getMorphedModel} (never naming a
     * sibling-module class, so the Variables one-way boundary holds), load un-scoped (so a departed member
     * / trashed system record still resolves — mirrors HasCreator::creator), and read the common `name` /
     * `icon` attributes. A type without those attributes (or a null actor) resolves to null and the UI
     * falls back to a per-type label.
     *
     * @param  array<int, array{actor_type: ?string, actor_id: ?string, cost: float, tokens: int}>  $rows
     * @return array<string, array{display_name: ?string, icon: ?string}>
     */
    private function resolveActorNames(array $rows): array
    {
        $idsByType = [];

        foreach ($rows as $row) {
            if ($row['actor_type'] !== null && $row['actor_id'] !== null) {
                $idsByType[$row['actor_type']][] = $row['actor_id'];
            }
        }

        $names = [];

        foreach ($idsByType as $type => $ids) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (!is_string($class) || !class_exists($class)) {
                continue;
            }

            $models = $class::query()->withoutGlobalScopes()->whereKey($ids)->get();

            foreach ($models as $model) {
                $names[$type . '|' . $model->getKey()] = [
                    'display_name' => is_scalar($model->getAttribute('name')) ? (string) $model->getAttribute('name') : null,
                    'icon' => is_scalar($model->getAttribute('icon')) ? (string) $model->getAttribute('icon') : null,
                ];
            }
        }

        return $names;
    }

    /** The start of the current calendar month (the rolling window boundary shared by every read). */
    private function monthStart(): Carbon
    {
        return Carbon::now()->startOfMonth();
    }
}
