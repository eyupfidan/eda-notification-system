<?php

use Illuminate\Support\Facades\Schedule;

// Release scheduled notifications whose time has come (at most ~10 s late).
Schedule::command('notifications:dispatch-scheduled')
    ->everyTenSeconds()
    ->withoutOverlapping()
    ->onOneServer();

// Safety net for notifications stuck after crashes or infrastructure hiccups.
Schedule::command('notifications:recover')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
