<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{title: string, url: string, summary: string|null, image_url: string|null, published_at: string|null}>  $articles
     */
    public function __construct(
        public readonly string $sourceName,
        public readonly array $articles,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->articles);

        return new Envelope(
            subject: "{$this->sourceName} — {$count} new ".($count === 1 ? 'article' : 'articles'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.daily-digest',
        );
    }
}
