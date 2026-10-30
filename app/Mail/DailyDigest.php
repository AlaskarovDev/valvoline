<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Gündəlik xülasə məktubu (DigestService::build məlumatı ilə).
 */
class DailyDigest extends Mailable
{
    /**
     * @param  array{date: string, user: string, kpis: list<array{label: string, value: string}>, alerts: list<array<string, mixed>>, url: string}  $digest
     */
    public function __construct(public array $digest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':app — gündəlik xülasə (:date)', ['app' => config('app.name'), 'date' => $this->digest['date']]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.digest');
    }
}
