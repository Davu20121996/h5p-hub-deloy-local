<?php

use App\Models\LtiLaunch;
use App\Models\LtiLogin;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    LtiLogin::where('expires_at', '<', now()->subDay())->delete();
    LtiLaunch::where('expires_at', '<', now()->subDay())->delete();
})->daily()->name('lti:prune-expired-logins')->withoutOverlapping();
