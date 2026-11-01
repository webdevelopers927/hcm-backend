<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Carries scalars only (no Employee model), so no PII ends up in the jobs table.
 */
class EmployeeInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $employeeId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to '.config('app.name'));
    }

    public function content(): Content
    {
        $url = rtrim((string) config('hcm.frontend_url'), '/').'/set-password?'.http_build_query([
            'token' => $this->token,
            'email' => $this->email,
        ]);

        return new Content(markdown: 'mail.employee-invitation', with: [
            'name' => $this->name,
            'url' => $url,
        ]);
    }
}
