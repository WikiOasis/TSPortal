<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CaseComment;
use App\Models\SafetyCase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CaseUpdatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SafetyCase $safetyCase,
        public string $recipient,
        public ?CaseComment $comment = null,
        public ?string $previousStatus = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('[%s] There is an update on your case', $this->safetyCase->reference),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.case-updated', with: [
            'case' => $this->safetyCase,
            'isReply' => $this->comment !== null,
            'statusLabel' => $this->statusLabel($this->safetyCase->status),
            'previousLabel' => $this->previousStatus !== null ? $this->statusLabel($this->previousStatus) : null,
            'homeUrl' => rtrim((string) config('mediawiki.central_url'), '/')
                .'/wiki/Special:SafetyHome/reports/'.$this->safetyCase->reference,
        ]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            SafetyCase::STATUS_RECEIVED => 'Received',
            SafetyCase::STATUS_IN_REVIEW => 'Being looked at',
            SafetyCase::STATUS_ACTION_TAKEN => 'Action taken',
            SafetyCase::STATUS_REJECTED => 'Closed with no action',
            SafetyCase::STATUS_DUPLICATE => 'Closed as a duplicate',
            default => 'Closed',
        };
    }
}
