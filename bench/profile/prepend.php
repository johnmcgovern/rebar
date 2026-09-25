<?php

/**
 * @file
 * Sampling profiler hook, auto-prepended in the rebar site's FPM pool only while
 * profiling (see bench/profile/run.sh). Profiles only requests from localhost
 * that send "X-Rebar-Profile: <label>", appending folded stacks (wall clock,
 * so time waiting on MariaDB counts) to <site>/rebar/profile/<label>.folded.
 */

if (($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1' && isset($_SERVER['HTTP_X_REBAR_PROFILE']) && extension_loaded('excimer')) {
  $rebar_profile_label = preg_replace('/[^a-z0-9_-]/i', '', $_SERVER['HTTP_X_REBAR_PROFILE']);
  $rebar_profiler = new ExcimerProfiler();
  $rebar_profiler->setPeriod(0.0005);
  $rebar_profiler->setEventType(EXCIMER_REAL);
  $rebar_profiler->start();
  register_shutdown_function(function () use ($rebar_profiler, $rebar_profile_label) {
    $rebar_profiler->stop();
    file_put_contents(__DIR__ . "/profile/$rebar_profile_label.folded", $rebar_profiler->getLog()->formatCollapsed(), FILE_APPEND | LOCK_EX);
  });
}
