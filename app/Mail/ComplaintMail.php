<?php

namespace App\Mail;

use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ComplaintMail extends Mailable
{
    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $complaint,
        public ?UploadedFile $file = null,
        public ?string $ip = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New WixChat complaint from ' . $this->senderName,
            // Hitting "Reply" in your inbox goes straight to the person who complained.
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.complaint',
        );
    }

    public function attachments(): array
    {
        if (!$this->file) {
            return [];
        }

        return [
            Attachment::fromPath($this->file->getRealPath())
                ->as($this->file->getClientOriginalName())
                ->withMime($this->file->getMimeType()),
        ];
    }
}