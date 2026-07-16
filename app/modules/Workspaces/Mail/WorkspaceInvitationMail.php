<?php

namespace App\Modules\Workspaces\Mail;

use App\Modules\Workspaces\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $workspaceName;

    public string $inviterName;

    public string $acceptUrl;

    /**
     * @param  string  $plainToken  The plaintext token (never the hash); only the
     *                              accept URL embeds it and it is never persisted.
     */
    public function __construct(WorkspaceInvitation $invitation, string $plainToken)
    {
        $this->workspaceName = $invitation->workspace->name;
        $this->inviterName = $invitation->invitedBy?->name ?? 'A Taskio user';
        $this->acceptUrl = config('app.url') . '/next/invitations/' . $plainToken;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->workspaceName} on Taskio",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.workspace-invitation',
            with: [
                'workspaceName' => $this->workspaceName,
                'inviterName' => $this->inviterName,
                'acceptUrl' => $this->acceptUrl,
            ],
        );
    }
}
