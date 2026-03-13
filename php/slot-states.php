<?php
/**
 * Slot States API
 * Returns current slot state overrides as JSON.
 * The frontend merges these over the default weekly schedule.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

$file = __DIR__ . '/slot-states.json';

if (!file_exists($file)) {
    echo '{}';
    exit;
}

$data = file_get_contents($file);
echo ($data !== false && $data !== '') ? $data : '{}';
