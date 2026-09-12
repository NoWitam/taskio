{{--
    Password reset link. Markup mirrors emails/workspace-invitation.blade.php (inline styles,
    no layout, one primary link plus a paste-able fallback) — the copy is translated because
    the recipient has an account and therefore a language; see PasswordResetMail for which one
    is chosen and why the request's own locale is not allowed to decide it.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('passwords.mail.subject') }}</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <p>{{ __('passwords.mail.greeting', ['name' => $userName]) }}</p>

    <p>{{ __('passwords.mail.intro') }}</p>

    <p>
        <a href="{{ $resetUrl }}"
           style="display: inline-block; padding: 10px 18px; background: #4f46e5; color: #ffffff; text-decoration: none; border-radius: 6px;">
            {{ __('passwords.mail.action') }}
        </a>
    </p>

    <p>{{ __('passwords.mail.fallback') }}</p>
    <p><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>

    <p style="color: #6b7280; font-size: 13px;">
        {{ __('passwords.mail.expires', ['minutes' => $expiresInMinutes]) }}
        {{ __('passwords.mail.ignore') }}
    </p>
</body>
</html>
