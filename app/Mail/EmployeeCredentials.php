<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sign-in details for a new employee (or a reset). Sent synchronously by SendEmployeeCredentials; never
 * queued on its own, because it carries the plain-text password.
 */
class EmployeeCredentials extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        public readonly ?string $employeeCode = null,
        public readonly bool $isReset = false,
    ) {}

    public function envelope(): Envelope
    {
        $brand = (string) config('hcm.brand_name');

        return new Envelope(subject: $this->isReset
            ? "Your new {$brand} password"
            : "Welcome to {$brand}: your sign-in details");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.employee-credentials',
            text: 'mail.employee-credentials-text',
            with: [
                'brand' => (string) config('hcm.brand_name'),
                'loginUrl' => rtrim((string) config('hcm.frontend_url'), '/').'/login',
            ],
        );
    }
}
