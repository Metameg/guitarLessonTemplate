<?php
/**
 * Slot Action Handler
 * Called from action buttons in the admin notification email.
 * Validates an HMAC token, then updates slot-states.json.
 *
 * Query params: slot, action, token
 *   slot   — "{dayIndex}_{hour}"  e.g. "0_9" = Monday 9 AM
 *   action — "accept" | "decline" | "cancel"
 *   token  — HMAC-SHA256( "{slot}:{action}", SLOT_SECRET )
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/mailer-config.php';

// ── Hour label helper ─────────────────────────────────────────
function fmtHour(int $h): string {
    if ($h === 12) return '12 PM';
    if ($h < 12)  return "{$h} AM";
    return ($h - 12) . ' PM';
}

// ── Validate inputs ───────────────────────────────────────────
$slot   = $_GET['slot']   ?? '';
$action = $_GET['action'] ?? '';
$token  = $_GET['token']  ?? '';

$validActions = ['accept', 'decline', 'cancel'];

$inputOk  = $slot !== '' && in_array($action, $validActions, true) && $token !== '';
$slotDate = null;
$slotHour = -1;

if ($inputOk) {
    // Slot key format: "YYYY-MM-DD_H"  e.g. "2026-03-16_9"
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{1,2})$/', $slot, $m)) {
        $slotDate = DateTimeImmutable::createFromFormat('Y-m-d', $m[1]);
        $slotHour = (int)$m[2];
        $inputOk  = $slotDate && $slotDate->format('Y-m-d') === $m[1] && $slotHour >= 0 && $slotHour <= 23;
    } else {
        $inputOk = false;
    }
}

if (!$inputOk) {
    http_response_code(400);
    renderPage('error', 'Invalid Request', 'This link is missing required parameters or contains an invalid slot reference.');
    exit;
}

// ── Verify HMAC ───────────────────────────────────────────────
$expected = hash_hmac('sha256', "{$slot}:{$action}", SLOT_SECRET);
if (!hash_equals($expected, $token)) {
    http_response_code(403);
    renderPage('error', 'Invalid Token', 'This action link is invalid or has been tampered with. Please use the original link from the email.');
    exit;
}

// ── Map action → new state ────────────────────────────────────
$newState = match($action) {
    'accept'  => 'booked',
    'decline' => 'available',
    'cancel'  => 'unavailable',
};

$actionLabel = match($action) {
    'accept'  => 'Accepted',
    'decline' => 'Declined',
    'cancel'  => 'Declined &amp; Cancelled',
};

$stateLabel = match($newState) {
    'booked'      => 'Booked',
    'available'   => 'Open',
    'unavailable' => 'Unavailable',
};

// ── Update slot-states.json ───────────────────────────────────
$file   = __DIR__ . '/slot-states.json';
$states = [];

if (file_exists($file)) {
    $json   = file_get_contents($file);
    $states = ($json !== false && $json !== '') ? (json_decode($json, true) ?? []) : [];
}

$states[$slot] = $newState;

$written = file_put_contents($file, json_encode($states, JSON_PRETTY_PRINT), LOCK_EX);

if ($written === false) {
    http_response_code(500);
    renderPage('error', 'Write Error', 'Could not save the slot state. Check that the php/ directory is writable by the web server.');
    exit;
}

// ── Success page ──────────────────────────────────────────────
$dayLabel  = $slotDate->format('l');              // "Monday"
$dateFmt   = $slotDate->format('M j, Y');         // "Mar 16, 2026"
$timeLabel = fmtHour($slotHour);
$slotLabel = "{$dayLabel}, {$dateFmt} at {$timeLabel}";

renderPage('success', $actionLabel, "The <strong>{$slotLabel}</strong> slot has been updated to <strong>{$stateLabel}</strong>.<br>The booking calendar will reflect this change immediately.");
exit;

// ── HTML render helper ────────────────────────────────────────
function renderPage(string $type, string $heading, string $body): void {
    $icon    = $type === 'success' ? '&#10003;' : '&#10007;';
    $color   = $type === 'success' ? '#2d8a2d' : '#8b2020';
    $border  = $type === 'success' ? '#3daa3d' : '#aa3d3d';
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>JCR Music Studio — Slot Action</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Georgia, serif; background: #080808; color: #f0f0f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
    .card { background: #111; border: 1px solid #222; border-radius: 12px; max-width: 480px; width: 100%; padding: 40px 32px; text-align: center; }
    .icon { width: 64px; height: 64px; border-radius: 50%; background: {$color}22; border: 2px solid {$border}; display: flex; align-items: center; justify-content: center; font-size: 28px; color: {$border}; margin: 0 auto 24px; }
    .logo { font-size: 13px; color: #8a8a8a; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 28px; }
    h1 { font-size: 22px; color: #c9a84c; margin-bottom: 16px; }
    p { font-size: 15px; color: #c0c0c0; line-height: 1.7; }
    strong { color: #f0f0f0; }
    .back { display: inline-block; margin-top: 28px; padding: 10px 24px; background: #1a1a1a; border: 1px solid #333; color: #c9a84c; text-decoration: none; border-radius: 6px; font-size: 13px; letter-spacing: 0.5px; }
    .back:hover { border-color: #c9a84c; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">JCR Music Studio</div>
    <div class="icon">{$icon}</div>
    <h1>{$heading}</h1>
    <p>{$body}</p>
    <a href="javascript:history.back()" class="back">← Go Back</a>
  </div>
</body>
</html>
HTML;
}
