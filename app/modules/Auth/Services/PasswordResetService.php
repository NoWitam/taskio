<?php

namespace App\Modules\Auth\Services;

use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use App\Modules\Auth\DTOs\ResetPasswordDTO;
use App\Modules\Auth\Mail\PasswordResetMail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * "I forgot my password", end to end.
 *
 * ───────────────────────────────────────────────────────────────────────────────────────
 * WHY IT EXISTS AT ALL (R4 / D5)
 *
 * Before R4 a forgotten password was an inconvenience: an owner could always be let back in
 * by hand. After R4 an account holds LIVE OAUTH TOKENS to the company's real social
 * channels, so losing control of it is losing control of what the company publishes — and
 * the recovery path has to be able to end somebody else's access, not just restore ours.
 * That is why the reset does more than change a column; see `rotateCredentials()`.
 *
 * ───────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS REUSED, AND WHY NOTHING IS HAND-ROLLED
 *
 * The token lifecycle is the FRAMEWORK'S password broker, unmodified: it creates the token,
 * stores only a hash, enforces the 60-minute expiry and the 60-second per-address cooldown
 * from `config/auth.php`, and deletes the token on use. Writing any of that by hand would be
 * a second, weaker implementation of something already in the box.
 *
 * Only the DELIVERY is ours, through the broker's `$callback` seam, so the mail matches this
 * product (Mailable + Blade + translated copy) instead of the framework's stock notification
 * — and the TIMING, for the reason below.
 *
 * ───────────────────────────────────────────────────────────────────────────────────────
 * THE ANSWER TAKES THE SAME TIME TO ARRIVE, AND THAT IS OURS, NOT THE FRAMEWORK'S
 *
 * Saying the same sentence for every address is only half of it: an answer that comes back
 * in 230 ms for a real account and 200 ms for a stranger has told the caller what the
 * sentence would not. {@see timebox()} pads BOTH entry points up to a common floor, read
 * from `auth.passwords.timebox_ms`.
 *
 * A NOTE ON WHY THE BROKER'S OWN `Timebox` WAS NOT ENOUGH, because it looks like it should
 * be and the first draft of this class said so: a timebox is a FLOOR, not an equaliser. It
 * sleeps the REMAINDER of a duration after the work finishes — it pads a fast branch and
 * lets a slow one run long, so it only equalises while every branch is faster than the
 * floor. The branch that finds an account is not: bcrypt-hashing the new token measured
 * 213-230 ms here at BCRYPT_ROUNDS=12, against the framework's 200 ms floor, before the mail
 * is even rendered. Ours is 1000 ms for that reason and the config comment names the
 * measurement it depends on. The broker's inner 200 ms floor stays where it is — harmless
 * underneath a higher one, and not ours to remove.
 *
 * ───────────────────────────────────────────────────────────────────────────────────────
 * THE ADDRESS IS NEVER CONFIRMED OR DENIED
 *
 * `request()` answers with ONE sentence for every input: sent, unknown address, and
 * cooled-down all produce identical bytes and an identical status. The broker's own results
 * (`passwords.sent` vs `passwords.user`) are exactly the pair that must not be distinguishable
 * — surfacing them would make this form a membership test against the user table, which is
 * worth more to an attacker than any single account (it tells them WHICH inboxes to phish).
 * The same reasoning already governs `auth.failed` on the login form.
 *
 * The cooldown is deliberately folded into that silence too. `RESET_THROTTLED` only ever
 * happens for an address that EXISTS and asked recently, so reporting it would leak both
 * facts at once. The cost is named and accepted: a person who clicks "send again" within a
 * minute is told the same thing as the first time and no second mail arrives.
 *
 * `reset()` collapses its two failures the same way. The broker checks the USER before the
 * TOKEN, so an unknown address would answer `passwords.user` while a known one with a bad
 * token answers `passwords.token` — an oracle on the second endpoint that would undo the
 * care taken on the first. Both become `passwords.token`; somebody holding a real link never
 * meets either.
 *
 * ───────────────────────────────────────────────────────────────────────────────────────
 * RATE LIMITING lives on the routes and is per-IP; the per-ADDRESS half is the broker's
 * `throttle` config. Two different things worth having: the route bucket stops one machine
 * spraying many addresses, the broker stops many machines flooding one inbox.
 */
class PasswordResetService
{
    /**
     * Used only if `auth.passwords.timebox_ms` is missing entirely — a config file that lost
     * the key must not silently drop the timing defence with it.
     */
    private const DEFAULT_TIMEBOX_MS = 1000;

    public function __construct(
        private AuthService $auth,
        private AuthContextCache $cache,
    ) {}

    /**
     * Ask for a reset link.
     *
     * Returns the SAME sentence whatever happened, and returns it for a syntactically valid
     * address that belongs to nobody just as readily as for a real account. Nothing is logged
     * here on purpose: an address paired with "a reset was requested" is precisely the record
     * a log aggregator should not be accumulating, and the token never leaves this method's
     * closure.
     */
    public function request(string $email): string
    {
        return $this->timebox(function () use ($email): string {
            Password::sendResetLink(
                ['email' => $email],
                fn (User $user, string $token) => $this->sendMail($user, $token),
            );

            return __('passwords.requested');
        });
    }

    /**
     * Redeem a mailed token and set the new password.
     *
     * THE TRANSACTION WRAPS THE WRITES AND NOTHING ELSE — see the comment inside. The whole
     * call is timeboxed, and a transaction may not be held open across a sleep.
     *
     * @return array<string, mixed> the login-shaped payload (message + token + context)
     *
     * @throws ValidationException on an invalid, expired or already-used link
     */
    public function reset(ResetPasswordDTO $dto): array
    {
        return $this->timebox(function () use ($dto): array {
            /** @var array<string, mixed>|null $session */
            $session = null;

            $status = Password::reset(
                [
                    'email' => $dto->email,
                    'password' => $dto->password,
                    'token' => $dto->token,
                ],
                function (User $user, string $password) use (&$session) {
                    // ONE TRANSACTION, AROUND THE WRITING AND ONLY THE WRITING: the password,
                    // the rotated credentials and the fresh session land together or not at
                    // all, so nobody ends up holding a token that outlived a rollback.
                    //
                    // IT USED TO WRAP THE WHOLE REDEMPTION, AND THAT BECAME A LIABILITY THE
                    // MOMENT THE FLOOR WENT UP. `Password::reset` sleeps inside its own
                    // timebox, and this method now sleeps inside a 1000 ms one; a transaction
                    // opened outside either of them means every hostile request — and this
                    // endpoint is a public, unauthenticated one that a script will hammer —
                    // holds a Postgres connection "idle in transaction" for a second while
                    // deliberately doing nothing. The pool is the thing that runs out.
                    //
                    // The cost of the narrower scope, named rather than discovered later: the
                    // broker's own delete of the spent token now runs just AFTER this commit
                    // instead of within it. A failure in that one statement would leave a live
                    // link for a password its owner already knows — reachable only from the
                    // inbox that just received it, and only until the 60-minute expiry. That
                    // is a smaller and rarer hole than a connection exhausted on demand.
                    $session = DB::transaction(function () use ($user, $password) {
                        $this->rotateCredentials($user, $password);

                        return $this->auth->issueToken($user);
                    });

                    // Dispatched after the commit, so a listener never observes a password
                    // change that is still rollbackable.
                    event(new PasswordReset($user));
                },
            );

            if ($status !== Password::PASSWORD_RESET || $session === null) {
                // One message for both broker failures — see the class docblock. The field is
                // `token` because that is what the user can act on: ask for a fresh link.
                // Thrown from INSIDE the timebox: a refusal that comes back faster than a
                // success is the same oracle in another shape.
                throw ValidationException::withMessages([
                    'token' => [__('passwords.token')],
                ]);
            }

            return ['message' => __('passwords.reset'), ...$session];
        });
    }

    /**
     * Run `$work` and do not return for less than the configured floor.
     *
     * `Timebox::call` measures the work, sleeps the remainder, and — the part that matters
     * here — does that for a THROWN exception too, so the 422 refusal of a dead link cannot
     * come back faster than a success.
     *
     * A FRESH `Timebox` PER CALL, DELIBERATELY NOT AN INJECTED OR CONTAINER-SHARED ONE.
     * `earlyReturn` is instance state and `PasswordBroker::reset()` calls `returnEarly()` on
     * its own timebox when a reset succeeds. Sharing one instance with the broker would let
     * that flag reach this outer call and skip the padding entirely — silently, on exactly
     * the branch whose duration is worth hiding.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    private function timebox(callable $work): mixed
    {
        return (new Timebox)->call(fn () => $work(), $this->timeboxMicroseconds());
    }

    /**
     * The floor in microseconds. Read per call (not cached in a property) so a test — or a
     * deployment — can change it without rebuilding the service.
     */
    private function timeboxMicroseconds(): int
    {
        $milliseconds = (int) config('auth.passwords.timebox_ms', self::DEFAULT_TIMEBOX_MS);

        return max(0, $milliseconds) * 1000;
    }

    /**
     * EVERYTHING THE OLD PASSWORD COULD STILL REACH, ENDED. This is the point of D5, so it is
     * spelled out rather than left to "changing the password logs you out somewhere":
     *
     *  1. THE PASSWORD ITSELF. Assigned raw and hashed by the model's `hashed` cast — the same
     *     way `ProfileController::updatePassword` does it. Hashing here would double-hash.
     *
     *  2. EVERY SANCTUM PERSONAL ACCESS TOKEN OF THIS USER, deleted outright — every browser,
     *     every device, every long-lived "remember me" login, including the attacker's. They
     *     are deleted BEFORE the fresh one is minted, so "all but the new session" is true by
     *     construction and not by an id comparison that could be got wrong. This is the part
     *     that actually severs access: a stolen bearer token survives a password change unless
     *     something deletes the row.
     *
     *  3. THE `remember_token`, rotated. The SPA is bearer-token only, but the `web` session
     *     guard exists and the column is real; leaving the old value would keep any remember
     *     cookie minted from it valid.
     *
     *  4. THE CACHED PERMISSION SET, versioned out, so the next context is compiled fresh.
     *
     * WHAT IS DELIBERATELY *NOT* TOUCHED: the workspace's stored platform credentials. A reset
     * proves control of an inbox; it is not a reason to disconnect the company's YouTube or
     * Meta account and break every scheduled publication. Cutting the intruder out of the
     * ACCOUNT is what this does; revoking the CHANNELS stays a deliberate act in Publishing,
     * which the rightful owner can now reach because they are back in.
     *
     * There is no server-side session store to clear as well: `SESSION_DRIVER` is `file` and
     * this installation never created the `sessions` table (its migration is still commented
     * out), so a Sanctum token IS what "a session" means here.
     */
    private function rotateCredentials(User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        $user->tokens()->delete();

        $this->cache->forget($user->id);
    }

    /**
     * SYNCHRONOUS, and the language follows the ACCOUNT rather than the request.
     *
     * The locale rule this method used to spell out in full now lives on
     * {@see SetUserLocale::accountLocale()} — extracted, unchanged, when R4's failed-publication mail
     * became the third caller that needs it. Its docblock keeps the whole argument, including why the
     * request's own `X-Client-Locale` may never decide the language of a letter delivered to somebody
     * else's inbox.
     */
    private function sendMail(User $user, #[\SensitiveParameter] string $plainToken): void
    {
        Mail::to($user->email)
            ->locale(SetUserLocale::accountLocale($user))
            ->send(new PasswordResetMail($user, $plainToken));
    }
}
