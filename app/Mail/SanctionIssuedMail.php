<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Sanction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SanctionIssuedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Sanction $sanction,
        public string $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('[%s] An action has been taken on your WikiOasis account', $this->sanction->reference),
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('mediawiki.central_url'), '/');

        return new Content(markdown: 'mail.sanction-issued', with: [
            'sanction' => $this->sanction,
            'appealUrl' => $base.'/wiki/Special:SafetyAppeal?ref='.urlencode($this->sanction->reference),
            'homeUrl' => $base.'/wiki/Special:SafetyHome/account',
        ]);
    }
}
