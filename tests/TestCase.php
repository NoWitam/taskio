<?php

namespace Tests;

use App\Modules\Bot\Tools\Support\HostResolver;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FixtureHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // NO TEST MAY REACH AN EMBEDDING PROVIDER. A blanket bind rather than something each indexing
        // test opts into, because the TRIGGER is not opt-in either: saving any knowledge entry queues
        // an indexing job, so a fixture in an unrelated test — a wikilink test, a trash test — would
        // spend real money on a sync queue. This makes that impossible by construction rather than by
        // remembering.
        //
        // A test that wants to OBSERVE spend re-binds the same instance it will assert against:
        //   $embedder = new FakeKnowledgeEmbedder;
        //   $this->app->instance(KnowledgeEmbedder::class, $embedder);
        $this->app->instance(KnowledgeEmbedder::class, new FakeKnowledgeEmbedder);

        // NO TEST MAY DEPEND ON LIVE DNS. The SSRF guard resolves hostnames, so ~10 fetch_url tests
        // needed working name resolution — and offline they failed fail-CLOSED, which is
        // indistinguishable at a glance from a real security regression. Only the LOOKUP is replaced;
        // every decision the guard makes about the addresses it gets back is untouched, and an
        // unmapped host still resolves to nothing (so the fail-closed backstop stays exercised).
        $this->app->instance(HostResolver::class, new FixtureHostResolver);
    }
}
