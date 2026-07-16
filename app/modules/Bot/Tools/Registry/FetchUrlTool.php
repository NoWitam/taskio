<?php

namespace App\Modules\Bot\Tools\Registry;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Tools\BotToolContext;
use App\Modules\Bot\Tools\Support\SafeUrlGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * fetch_url(url): fetch a public web page and return its plain text (HTML stripped,
 * whitespace collapsed, truncated).
 *
 * SSRF hardening:
 *   - SafeUrlGuard validates the URL and returns the single validated IP; that IP is
 *     PINNED into the connection via CURLOPT_RESOLVE so cURL connects to exactly what
 *     was validated (closes the DNS-rebinding TOCTOU window). Re-validated AND re-pinned
 *     on EVERY redirect hop.
 *   - The body is STREAMED and aborted once it exceeds fetch_max_bytes (chunked reader),
 *     and CURLOPT_MAXFILESIZE guards Content-Length responses — no full download into
 *     memory.
 *
 * Records a `tool_used` action carrying only the host/url (never the fetched content).
 */
class FetchUrlTool implements Tool
{
    /** The canonical validated host of the INITIAL request (for the tool_used payload). */
    private ?string $canonicalHost = null;

    public function __construct(
        private BotToolContext $ctx,
        private SafeUrlGuard $guard,
    ) {}

    public function description(): Stringable|string
    {
        return 'Pobiera publiczną stronę WWW (http/https) i zwraca jej treść jako czysty tekst. '
            . 'Używaj do sprawdzania faktów lub źródeł. Adresy prywatne/lokalne są zablokowane.';
    }

    public function handle(Request $request): Stringable|string
    {
        $url = trim((string) ($request['url'] ?? ''));

        try {
            $text = $this->fetch($url);
        } catch (RuntimeException $e) {
            return 'Nie udało się pobrać strony: ' . $e->getMessage();
        }

        $this->ctx->actions->record($this->ctx->bot, $this->ctx->task, BotActionType::ToolUsed, [
            'tool' => 'fetch_url',
            // Canonical validated host (dot-stripped / numeric-normalized); `url` is the
            // ORIGINAL user-facing request URL.
            'host' => $this->canonicalHost ?? parse_url($url, PHP_URL_HOST),
            'url' => $url,
        ]);

        return $text;
    }

    private function fetch(string $url): string
    {
        $timeout = (int) config('ai.fetch_timeout', 10);
        $maxBytes = (int) config('ai.fetch_max_bytes', 2 * 1024 * 1024);
        $maxRedirects = (int) config('ai.fetch_max_redirects', 3);

        // Validate + pin the initial request. The request URL's host is rewritten to the
        // CANONICAL validated host so URL-host == the CURLOPT_RESOLVE pin key (otherwise
        // cURL re-resolves the original host and the pin is inert — G1).
        $pin = $this->guard->validate($url);
        $this->canonicalHost = $pin['host'];
        $response = $this->request($this->buildPinnedUrl($url, $pin['host']), $pin, $timeout, $maxBytes);

        // Follow redirects manually so each hop is re-validated AND re-pinned.
        $redirects = 0;
        while ($response->redirect() && $redirects < $maxRedirects) {
            $location = $response->header('Location');
            if ($location === null || $location === '') {
                break;
            }

            $url = $this->resolveLocation($url, $location);
            $pin = $this->guard->validate($url);
            $response = $this->request($this->buildPinnedUrl($url, $pin['host']), $pin, $timeout, $maxBytes);
            $redirects++;
        }

        if ($response->redirect()) {
            throw new RuntimeException('Zbyt wiele przekierowań.');
        }

        return $this->htmlToText($this->readCappedStream($response, $maxBytes));
    }

    /**
     * Issue a single (non-redirecting) request with the validated IP pinned and a hard
     * response-size cap. Streaming so the body is read incrementally, never buffered.
     *
     * @param  array{host: string, ip: string, port: int}  $pin
     */
    private function request(string $url, array $pin, int $timeout, int $maxBytes): Response
    {
        return Http::withoutRedirecting()
            ->timeout($timeout)
            ->withOptions([
                'stream' => true,
                'curl' => $this->curlPinOptions($pin['host'], $pin['port'], $pin['ip'], $maxBytes),
            ])
            ->get($url);
    }

    /**
     * cURL options that PIN the connection to the validated IP and cap the transfer size.
     * Extracted for direct assertion in tests (Http::fake bypasses cURL, so the pinning
     * itself is verified at the option level, not via a live socket).
     *
     * @return array<int, mixed>
     */
    public function curlPinOptions(string $host, int $port, string $ip, int $maxBytes): array
    {
        return [
            // host:port:ip — cURL connects to $ip for $host:$port, so what-was-validated
            // == what-connects even if DNS would now rebind to a private address.
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            // Abort Content-Length responses that declare a size over the cap up front.
            CURLOPT_MAXFILESIZE => $maxBytes,
        ];
    }

    /**
     * Rebuild the request URL with its host replaced by the CANONICAL validated host, so
     * the host cURL parses from the URL equals the CURLOPT_RESOLVE pin key. Without this,
     * a trailing-dot FQDN (evil.com.) or numeric/mapped host would keep its original form
     * in the URL, the pin entry would miss, and cURL would re-resolve independently —
     * reopening the DNS-rebinding window (G1). Pure and testable.
     *
     * Stripping a trailing dot / normalizing a numeric host to its dotted-quad is
     * semantically equivalent, and ordinary domains are returned unchanged, so the Host
     * header (and any name-based vhost) is unaffected.
     */
    public function buildPinnedUrl(string $url, string $canonicalHost): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'])) {
            return $url;
        }

        // IPv6 literals must stay bracketed in the URL authority.
        $hostInUrl = str_contains($canonicalHost, ':') ? "[{$canonicalHost}]" : $canonicalHost;

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return "{$parts['scheme']}://{$hostInUrl}{$port}{$path}{$query}{$fragment}";
    }

    /**
     * Read the PSR body in chunks and ABORT once the read exceeds $maxBytes — covers
     * chunked/streamed responses with no declared Content-Length. Extracted so a fake
     * in-memory stream can prove it stops past the cap without materializing the rest.
     */
    public function readCappedStream(Response $response, int $maxBytes): string
    {
        $stream = $response->toPsrResponse()->getBody();

        $buffer = '';
        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);

            if (strlen($buffer) > $maxBytes) {
                throw new RuntimeException('Odpowiedź przekracza dozwolony rozmiar.');
            }
        }

        return $buffer;
    }

    /** Resolve a redirect Location against the current URL (absolute or relative). */
    private function resolveLocation(string $base, string $location): string
    {
        if (Str::startsWith($location, ['http://', 'https://'])) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (Str::startsWith($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }

        return "{$scheme}://{$host}{$port}/{$location}";
    }

    /** Strip scripts/styles/tags, decode entities, collapse whitespace, truncate. */
    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        $maxChars = (int) config('ai.fetch_max_chars', 20000);

        return Str::limit($text, $maxChars, '…');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('Publiczny adres URL (http/https) do pobrania.')
                ->required(),
        ];
    }
}
