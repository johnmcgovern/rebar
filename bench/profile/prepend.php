<?php

/**
 * @file
 * Sampling profiler hook, auto-prepended in the drupal02 FPM pool only while
 * profiling (see bench/profile/run.sh). Profiles only requests from localhost
 * that send "X-Drust-Profile: <label>", appending folded stacks (wall clock,
 * so time waiting on MariaDB counts) to <site>/drust/profile/<label>.folded.
 */

if (($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1' && isset($_SERVER['HTTP_X_DRUST_PROFILE']) && extension_loaded('excimer')) {
  $drust_profile_label = preg_replace('/[^a-z0-9_-]/i', '', $_SERVER['HTTP_X_DRUST_PROFILE']);
  $drust_profiler = new ExcimerProfiler();
  $drust_profiler->setPeriod(0.0005);
  $drust_profiler->setEventType(EXCIMER_REAL);
  $drust_profiler->start();
  register_shutdown_function(function () use ($drust_profiler, $drust_profile_label) {
    $drust_profiler->stop();
    file_put_contents(__DIR__ . "/profile/$drust_profile_label.folded", $drust_profiler->getLog()->formatCollapsed(), FILE_APPEND | LOCK_EX);
  });
}
