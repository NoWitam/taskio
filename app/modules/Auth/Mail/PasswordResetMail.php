<?php

namespace App\Modules\Auth\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The password-reset link mail. Built on the ONE mail this product already sends —
 * {@see \App\Modules\Workspaces\Mail\WorkspaceInvitationMail} — same Mailable shape, same
 * inline-styled Blade next to it in `resources/views/emails/`, same synchronous
 * `Mail::to(...)->send()` from the service.
 *
 * IT DIFFERS FROM THE INVITATION IN ONE WAY, ON PURPOSE: the invitation's copy is hardcoded
 * English. This one is TRANSLATED, because a reset mail is read by an account holder who has
 * already told us which language they read in, and a Polish installation sending an English
 * "reset your password" is the same defect the locale middleware was written to fix. The copy
 * lives in `lang/{en,pl}/passwords.php` — the file that already holds both languages of every
 * other sentence in this flow, including the broker's own results.
 *
 * WHICH LANGUAGE (decided in the service, applied by `Mail::to()->locale()`): the account's
 * STORED CHOICE (`users.locale`) if this installation can speak it, otherwise `APP_LOCALE`.
 * The `X-Client-Locale` header that {@see \App\Http\Middleware\SetUserLocale} honours for the
 * HTTP RESPONSE is deliberately NOT consulted here, and that is a security property rather
 * than a style one: anyone may POST an address they do not own, so letting the REQUEST choose
 * the language would let a stranger pick the language of a mail delivered to somebody else's
 * inbox. The reply on the screen follows the screen; the letter follows the account.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $userName;

    public string $resetUrl;

    /** Minutes the link stays valid — read from the broker's own config, never a second copy. */
    public int $expiresInMinutes;

    /**
     * @param  string  $plainToken  The plaintext reset token (the store holds only its hash).
     *                              It reaches exactly one place — the URL below — and is never
     *                              logged, persisted or echoed back over HTTP.
     */
    public function __construct(User $user, #[\SensitiveParameter] string $plainToken)
    {
        $this->userName = $user->name;
        $this->expiresInMinutes = (int) config('auth.passwords.users.expire', 60);

        // Same shape as the invitation's accept URL: an absolute link into the SPA, whose
        // router owns everything under /next. The email rides along so the reset form can
        // submit the pair the broker checks together, without the person retyping it.
        $this->resetUrl = config('app.url') . '/next/reset-password?' . http_build_query([
            'token' => $plainToken,
            'email' => $user->email,
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('passwords.mail.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
            with: [
                'userName' => $this->userName,
                'resetUrl' => $this->resetUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }
}
