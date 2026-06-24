<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workspace invitation</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <p>Hi,</p>

    <p>
        <strong>{{ $inviterName }}</strong> has invited you to join the workspace
        <strong>{{ $workspaceName }}</strong> on Taskio.
    </p>

    <p>
        <a href="{{ $acceptUrl }}"
           style="display: inline-block; padding: 10px 18px; background: #4f46e5; color: #ffffff; text-decoration: none; border-radius: 6px;">
            Accept invitation
        </a>
    </p>

    <p>Or paste this link into your browser:</p>
    <p><a href="{{ $acceptUrl }}">{{ $acceptUrl }}</a></p>

    <p style="color: #6b7280; font-size: 13px;">
        This invitation will expire in 7 days. If you weren't expecting it, you can ignore this email.
    </p>
</body>
</html>
