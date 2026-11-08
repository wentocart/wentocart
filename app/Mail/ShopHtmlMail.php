<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ShopHtmlMail extends Mailable
{
    /**
     * @param  list<array{filename: string, content: string, mime?: string}>  $fileAttachments
     */
    public function __construct(
        public string $htmlBody,
        public string $emailSubject,
        public array $fileAttachments = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $out = [];
        foreach ($this->fileAttachments as $file) {
            if (! is_array($file) || empty($file['content']) || empty($file['filename'])) {
                continue;
            }
            $out[] = Attachment::fromData(
                fn () => (string) $file['content'],
                (string) $file['filename'],
            )->withMime((string) ($file['mime'] ?? 'application/octet-stream'));
        }

        return $out;
    }
}
