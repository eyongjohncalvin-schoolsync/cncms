<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "A new workspace is waiting for your approval" — sent to every active
 * landlord when a company registers (email or Google sign-up), from
 * App\Services\WorkspaceProvisioningService::provision(). Platform branded:
 * no tenant is initialized when it is built. Plain values, not the Tenant
 * model, because the provisioning job clears the pending company details
 * before this queued mail is sent.
 */
class NewWorkspaceRegisteredMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $workspaceName,
        public readonly string $workspaceSlug,
        public readonly string $ownerName,
        public readonly string $ownerEmail,
        public readonly ?string $location,
        public readonly ?string $phone,
        public readonly string $landlordName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New workspace waiting for approval: {$this->workspaceName}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-workspace-registered',
            with: [
                'landlordName' => $this->landlordName,
                'workspaceName' => $this->workspaceName,
                'workspaceSlug' => $this->workspaceSlug,
                'ownerName' => $this->ownerName,
                'ownerEmail' => $this->ownerEmail,
                'location' => $this->location,
                'phone' => $this->phone,
                'url' => url('/landlord/tenants?status=pending'),
            ],
        );
    }
}
