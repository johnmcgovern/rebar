<?php

/**
 * @file
 * Sequential HTTP benchmark: warms each URL, then reports latency stats.
 *
 * Usage: php bench.php <base-url> <requests-per-url> <path>...
 */

[, $base, $n] = $argv;
$paths = array_slice($argv, 3);
$ch = curl_init();
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_FOLLOWLOCATION => FALSE]);

$fetch = function (string $path) use ($ch, $base): array {
  curl_setopt($ch, CURLOPT_URL, $base . $path);
  $t = hrtime(TRUE);
  $body = curl_exec($ch);
  $ms = (hrtime(TRUE) - $t) / 1e6;
  return [$ms, curl_getinfo($ch, CURLINFO_RESPONSE_CODE), strlen((string) $body)];
};

printf("%-22s %7s %8s %8s %8s %6s\n", 'path', 'status', 'median', 'p95', 'mean', 'KB');
foreach ($paths as $path) {
  [$cold] = $fetch($path);
  for ($i = 0; $i < 20; $i++) {
    $fetch($path);
  }
  $times = [];
  for ($i = 0; $i < $n; $i++) {
    [$ms, $status, $bytes] = $fetch($path);
    $times[] = $ms;
  }
  sort($times);
  printf("%-22s %7d %7.2fms %7.2fms %7.2fms %6.1f   (first hit %.0fms)\n", $path, $status,
    $times[intdiv($n, 2)], $times[(int) floor($n * 0.95)], array_sum($times) / $n, $bytes / 1024, $cold);
}
