<?php

use App\Models\H5pAttempt;
use App\Models\LtiLaunch;
use App\Models\LtiLogin;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    LtiLogin::where('expires_at', '<', now()->subDay())->delete();
    LtiLaunch::where('expires_at', '<', now()->subDay())
        ->whereNotIn('id', H5pAttempt::select('lti_launch_id'))->delete();
})->daily()->name('lti:prune-expired-logins')->withoutOverlapping();
