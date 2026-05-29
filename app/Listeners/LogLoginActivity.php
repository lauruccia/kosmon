<?php

namespace App\Listeners;

use App\Models\LoginLog;
use App\Notifications\NewIpLoginNotification;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogLoginActivity implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(Login $event): void
    {
        $user    = $event->user;
        $request = request();

        $ip        = $request->ip();
        $userAgent = $request->userAgent() ?? '';

        // Controlla se questo IP ha già fatto login
        $isNewIp = ! LoginLog::where('user_id', $user->id)
            ->where('ip_address', $ip)
            ->exists();

        // Analisi user-agent
        [$deviceType, $browser, $os] = $this->parseUserAgent($userAgent);

        // Geolocalizzazione IP (usa ip-api.com free, fallback silenzioso)
        [$country, $city] = $this->geolocate($ip);

        $log = LoginLog::create([
            'user_id'     => $user->id,
            'ip_address'  => $ip,
            'user_agent'  => mb_substr($userAgent, 0, 255),
            'country'     => $country,
            'city'        => $city,
            'device_type' => $deviceType,
            'browser'     => $browser,
            'os'          => $os,
            'is_new_ip'   => $isNewIp,
        ]);

        // Alert email se IP nuovo
        if ($isNewIp && LoginLog::where('user_id', $user->id)->count() > 1) {
            $user->notify(new NewIpLoginNotification($log));
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    private function parseUserAgent(string $ua): array
    {
        // Device type
        $deviceType = 'desktop';
        if (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|iemobile/i', $ua)) {
            $deviceType = 'mobile';
        } elseif (preg_match('/tablet|ipad/i', $ua)) {
            $deviceType = 'tablet';
        }

        // Browser
        $browser = 'Sconosciuto';
        if (preg_match('/Edg\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR\//i', $ua) || preg_match('/Opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Chrome\/(\d+)/i', $ua, $m)) {
            $browser = 'Chrome ' . $m[1];
        } elseif (preg_match('/Firefox\/(\d+)/i', $ua, $m)) {
            $browser = 'Firefox ' . $m[1];
        } elseif (preg_match('/Safari\/(\d+)/i', $ua, $m)) {
            $browser = 'Safari';
        } elseif (preg_match('/MSIE|Trident/i', $ua)) {
            $browser = 'Internet Explorer';
        }

        // OS
        $os = 'Sconosciuto';
        if (preg_match('/Windows NT (\d+\.\d+)/i', $ua, $m)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Android (\d+)/i', $ua, $m)) {
            $os = 'Android ' . $m[1];
        } elseif (preg_match('/iPhone OS ([\d_]+)/i', $ua, $m)) {
            $os = 'iOS ' . str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        return [$deviceType, $browser, $os];
    }

    private function geolocate(string $ip): array
    {
        // Skip per IP locali / privati
        if (in_array($ip, ['127.0.0.1', '::1'], true)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            return ['Locale', null];
        }

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 3]]);
            $json = @file_get_contents(
                "http://ip-api.com/json/{$ip}?fields=country,city,status",
                false,
                $ctx
            );
            if ($json) {
                $data = json_decode($json, true);
                if (($data['status'] ?? '') === 'success') {
                    return [$data['country'] ?? null, $data['city'] ?? null];
                }
            }
        } catch (\Throwable) {
            // fallback silenzioso
        }

        return [null, null];
    }
}
