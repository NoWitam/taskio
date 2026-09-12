{{--
    A publication did not go out (R4 D4). Markup mirrors emails/password-reset.blade.php — inline
    styles, no layout, one primary link plus a paste-able fallback — and the copy is translated for
    the same reason: the reader has an account and therefore a language. See PublicationFailedMail
    for which language is chosen, and for the three things this letter may never contain
    (failure_context, an exception, anything derived from a credential).

    The reason line and the planned moment are CONDITIONAL rather than shown empty: a row with no
    failure code explains nothing, and a publication with no moment had none to miss. A labelled
    blank invites the reader to interpret it.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('publishing.mail.failed.subject', ['title' => $displayTitle]) }}</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <p>{{ __('publishing.mail.failed.greeting', ['name' => $recipientName]) }}</p>

    <p>{{ __('publishing.mail.failed.intro', ['title' => $displayTitle, 'platform' => $platformLabel]) }}</p>

    @if ($failureSentence !== null)
        <p>{{ $failureSentence }}</p>
    @endif

    @if ($plannedFor !== null)
        <p style="color: #6b7280; font-size: 13px;">
            {{ __('publishing.mail.failed.planned', ['moment' => $plannedFor, 'timezone' => $timezone]) }}
        </p>
    @endif

    <p>
        <a href="{{ $publicationUrl }}"
           style="display: inline-block; padding: 10px 18px; background: #4f46e5; color: #ffffff; text-decoration: none; border-radius: 6px;">
            {{ __('publishing.mail.failed.action') }}
        </a>
    </p>

    <p>{{ __('publishing.mail.failed.fallback') }}</p>
    <p><a href="{{ $publicationUrl }}">{{ $publicationUrl }}</a></p>

    <p style="color: #6b7280; font-size: 13px;">
        {{ __('publishing.mail.failed.footer') }}
    </p>
</body>
</html>
