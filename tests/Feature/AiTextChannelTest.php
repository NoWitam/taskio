<?php

namespace Tests\Feature;

use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Services\AiTextGenerationService;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;
use Tests\TestCase;

/**
 * BYTE FREEZE of the metered CHANNEL on the shared structured-text seam.
 *
 * B11a gave {@see AiTextGenerationService::generateWith()} an optional trailing `$channel` so the
 * knowledge composer's spend could be bucketed separately from workflow text. The whole safety of that
 * change rests on one property: every pre-existing caller — four of them, across the Bot and Generator
 * modules — passes no channel and must therefore keep metering on `ai_text` exactly as before. The
 * meter buckets the price, the ledger and the operator's monthly view, so a silent re-bucketing would
 * move real money between columns with nothing failing.
 *
 * The seam is exercised through a RECORDING meter rather than a mock, so what is asserted is the
 * channel string the meter actually received.
 */
class AiTextChannelTest extends TestCase
{
    private function service(RecordingChannelMeter $meter): AiTextGenerationService
    {
        $this->app->instance(MeteredAiCall::class, $meter);

        return $this->app->make(AiTextGenerationService::class);
    }

    /** No channel argument — the pre-B11a call shape — still meters on `ai_text`. */
    public function test_a_caller_that_passes_no_channel_still_meters_on_ai_text(): void
    {
        $meter = new RecordingChannelMeter;

        $this->service($meter)->generateWith(new ChannelProbeAgent, 'cokolwiek', 100);

        $this->assertSame(['ai_text'], $meter->channels);
    }

    /** ...including with the timeout argument, which used to be the last parameter. */
    public function test_the_timeout_argument_did_not_move(): void
    {
        $meter = new RecordingChannelMeter;

        $this->service($meter)->generateWith(new ChannelProbeAgent, 'cokolwiek', 100, 30);

        $this->assertSame(['ai_text'], $meter->channels);
    }

    /** An explicit null is the same thing as absent — the default is a value, not a special case. */
    public function test_an_explicit_null_channel_is_ai_text(): void
    {
        $meter = new RecordingChannelMeter;

        $this->service($meter)->generateWith(new ChannelProbeAgent, 'cokolwiek', 100, null, null);

        $this->assertSame(['ai_text'], $meter->channels);
    }

    /** And a caller that names one gets it. */
    public function test_a_named_channel_is_used(): void
    {
        $meter = new RecordingChannelMeter;

        $this->service($meter)->generateWith(new ChannelProbeAgent, 'cokolwiek', 100, null, 'ai_knowledge');

        $this->assertSame(['ai_knowledge'], $meter->channels);
    }

    /** The new channel is priced, or its spend would silently record as free. */
    public function test_the_knowledge_channel_has_a_price(): void
    {
        $this->assertIsFloat(config('ai.meter.pricing.ai_knowledge.per_1k_tokens'));
        $this->assertGreaterThan(0, config('ai.meter.pricing.ai_knowledge.per_1k_tokens'));
    }
}

/** Records the channel each metered call was bucketed under, then runs the call for real. */
class RecordingChannelMeter implements MeteredAiCall
{
    /** @var array<int, string> */
    public array $channels = [];

    public function meter(string $channel, callable $call): mixed
    {
        $this->channels[] = $channel;

        return $call();
    }

    public function assertWithinBudget(string $channel, float $projectedCost = 0.0): void {}
}

/** A stand-in agent; its reply is irrelevant — only the channel the meter saw is under test. */
class ChannelProbeAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'probe';
    }
}
