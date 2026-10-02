<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use ParticleAcademy\LaravelJobs\Models\JobApplication;

/**
 * Tells an employer a candidate applied.
 *
 * Deliberately says nothing about the candidate beyond their name and the
 * posting. The application carries a CV path, a cover letter, an email and a
 * phone number, and an email notification is the easiest place in a system to
 * leak all four -- a mail body is archived, forwarded, and readable by whoever
 * reaches the inbox, with none of the authorization the download route applies.
 *
 * So this links to the portal and makes the employer come and be authorised.
 */
class ApplicationReceived extends Notification
{
    use Queueable;

    public function __construct(public readonly JobApplication $application)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $posting = $this->application->jobPosting;

        return (new MailMessage())
            ->subject('New application: '.($posting->title ?? 'a posting'))
            ->line('You have a new application to review.')
            // No resume path, no contact details, no cover letter. On purpose.
            ->action('Review it', url('/applications/'.$this->application->getKey().'/resume'));
    }
}
