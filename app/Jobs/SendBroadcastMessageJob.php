<?php

namespace App\Jobs;

use App\Models\Company;
use App\Notifications\BroadcastNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBroadcastMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly array  $companyIds,
        public readonly string $subject,
        public readonly string $body,
        public readonly array  $channels,
        public readonly int    $senderId,
    ) {}

    public function handle(): void
    {
        $companies = Company::whereIn('id', $this->companyIds)
            ->with('users')
            ->get();

        foreach ($companies as $company) {
            $user = $company->users()->first();
            if (! $user) continue;

            if (in_array('in_app', $this->channels, true)) {
                $user->notify(new BroadcastNotification($this->subject, $this->body));
            }

            if (in_array('email', $this->channels, true) && $user->email) {
                try {
                    Mail::raw($this->body, function ($m) use ($user) {
                        $m->to($user->email)->subject($this->subject);
                    });
                } catch (\Throwable $e) {
                    Log::warning('broadcast.email_mail_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
