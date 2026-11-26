<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for notifications stuck after crashes or infrastructure hiccups.
Schedule::command('notifications:recover')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
