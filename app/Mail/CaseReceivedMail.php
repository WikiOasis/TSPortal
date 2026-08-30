<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SafetyCase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CaseReceivedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SafetyCase $safetyCase,
        public string $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('[%s] Trust & Safety has your %s', $this->safetyCase->reference, $this->noun()),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.case-received', with: [
            'case' => $this->safetyCase,
            'noun' => $this->noun(),
            'homeUrl' => rtrim((string) config('mediawiki.central_url'), '/').'/wiki/Special:SafetyHome',
        ]);
    }

    private function noun(): string
    {
        return match ($this->safetyCase->type) {
            SafetyCase::TYPE_APPEAL => 'appeal',
            SafetyCase::TYPE_DATA => 'data request',
            SafetyCase::TYPE_CONTACT => 'message',
            default => 'report',
        };
    }
}
