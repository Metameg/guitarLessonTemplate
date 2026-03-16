<?php
/**
 * Slot Manager — Secret Admin Page
 * Access: /php/manage-slots.php?key=YOUR_MANAGE_KEY
 *
 * Lets the site owner view and change any specific date's lesson slot state.
 * When a slot has a pending student booking, a popup asks whether to email them.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$dotenv->required(['MANAGE_KEY']);
define('MANAGE_KEY', $_ENV['MANAGE_KEY']);

// ── Auth ──────────────────────────────────────────────────────
$key = $_GET['key'] ?? '';
if ($key === '' || !hash_equals(MANAGE_KEY, $key)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>403</title>
    <style>body{background:#06091a;color:#8ba3c7;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
    .box{text-align:center;}.box h1{color:#ef4444;font-size:48px;margin:0;}.box p{margin-top:8px;}</style></head>
    <body><div class="box"><h1>403</h1><p>Access denied.</p></div></body></html>';
    exit;
}

// ── Week offset ───────────────────────────────────────────────
$weekOffset = (int)($_GET['week'] ?? 0);

// ── Compute Monday of the target week ────────────────────────
function getMondayOf(int $offset): DateTimeImmutable {
    $today = new DateTimeImmutable('today');
    $dow   = (int)$today->format('N'); // 1=Mon, 7=Sun
    $toMon = $dow === 7 ? -6 : 1 - $dow;
    return $today->modify("{$toMon} days")->modify(($offset * 7) . ' days');
}

$monday   = getMondayOf($weekOffset);
$saturday = $monday->modify('+5 days');

// ── Base schedule (mirrors js/main.js) ────────────────────────
$baseSchedule = [
    0 => [ 9=>'booked',10=>'available',11=>'available',12=>'unavailable',13=>'unavailable',14=>'booked',15=>'available',16=>'available',17=>'available',18=>'booked',19=>'unavailable' ],
    1 => [ 9=>'available',10=>'available',11=>'booked',12=>'unavailable',13=>'unavailable',14=>'available',15=>'booked',16=>'available',17=>'booked',18=>'available',19=>'available' ],
    2 => [ 9=>'unavailable',10=>'booked',11=>'booked',12=>'booked',13=>'unavailable',14=>'available',15=>'available',16=>'booked',17=>'available',18=>'available',19=>'unavailable' ],
    3 => [ 9=>'available',10=>'booked',11=>'available',12=>'unavailable',13=>'unavailable',14=>'available',15=>'available',16=>'booked',17=>'available',18=>'booked',19=>'available' ],
    4 => [ 9=>'booked',10=>'booked',11=>'available',12=>'unavailable',13=>'unavailable',14=>'booked',15=>'available',16=>'available',17=>'booked',18=>'available',19=>'available' ],
    5 => [ 9=>'available',10=>'available',11=>'booked',12=>'available',13=>'available',14=>'booked',15=>'available',16=>'unavailable',17=>'unavailable',18=>'unavailable',19=>'unavailable' ],
];
$hours = [9,10,11,12,13,14,15,16,17,18,19];

function fmtHour(int $h): string {
    if ($h === 12) return '12 PM';
    return $h < 12 ? "{$h} AM" : ($h - 12) . ' PM';
}

// ── Handle state update ───────────────────────────────────────
$flashMsg  = '';
$flashType = '';
$slotFile  = __DIR__ . '/slot-states.json';
$validStates = ['available', 'booked', 'unavailable'];

if (isset($_GET['slot'], $_GET['state'])) {
    $updSlot  = $_GET['slot'];
    $updState = $_GET['state'];

    if (
        in_array($updState, $validStates, true) &&
        preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{1,2})$/', $updSlot, $sm)
    ) {
        $overrides = [];
        if (file_exists($slotFile)) {
            $raw = file_get_contents($slotFile);
            $overrides = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?? []) : [];
        }
        $overrides[$updSlot] = $updState;
        $written = file_put_contents($slotFile, json_encode($overrides, JSON_PRETTY_PRINT), LOCK_EX);

        // Optional student notification
        $emailSent  = false;
        $notifyFlag = $_GET['notify'] ?? null;

        require_once __DIR__ . '/notify-student.php';

        if ($notifyFlag === '1') {
            require_once __DIR__ . '/mailer-config.php';
            $emailSent = notifyStudent($updSlot, $updState);
        }

        // Always clear pending booking once owner has acted
        if ($notifyFlag !== null) {
            clearPendingBooking($updSlot);
        }

        // Build label for flash
        $dt         = DateTimeImmutable::createFromFormat('Y-m-d', $sm[1]);
        $slotLabel  = $dt ? $dt->format('l, M j') . ' at ' . fmtHour((int)$sm[2]) : $updSlot;
        $stateLabel = match($updState) {
            'available'   => 'Open',
            'booked'      => 'Booked',
            'unavailable' => 'Unavailable',
        };

        if ($written !== false) {
            $flashExtra = $emailSent ? ':notified' : '';
            $flashType  = 'success';
        } else {
            $flashExtra = ':writefail';
            $flashType  = 'error';
        }

        $qs = http_build_query(['key' => $key, 'week' => $weekOffset, 'flash' => $updSlot . ':' . $updState . $flashExtra]);
        header("Location: manage-slots.php?{$qs}");
        exit;
    }
}

// Restore flash from redirect
if (!$flashMsg && isset($_GET['flash'])) {
    $fp = explode(':', $_GET['flash'], 3); // ["2026-03-16_9", "booked", "notified"|"writefail"|""]
    if (count($fp) >= 2 && in_array($fp[1], $validStates, true)) {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{1,2})$/', $fp[0], $sm)) {
            $dt         = DateTimeImmutable::createFromFormat('Y-m-d', $sm[1]);
            $slotLabel  = $dt ? $dt->format('l, M j') . ' at ' . fmtHour((int)$sm[2]) : $fp[0];
            $stateLabel = match($fp[1]) {
                'available' => 'Open', 'booked' => 'Booked', 'unavailable' => 'Unavailable', default => $fp[1],
            };
            $notified  = ($fp[2] ?? '') === 'notified';
            $writeFail = ($fp[2] ?? '') === 'writefail';

            if ($writeFail) {
                $flashMsg  = "&#10007; &nbsp; Could not save changes. Check that the <code>php/</code> folder is writable on the server.";
                $flashType = 'error';
            } else {
                $emailNote = $notified ? ' &mdash; student notified by email' : '';
                $flashMsg  = "&#10003; &nbsp; <strong>{$slotLabel}</strong> updated to <strong>{$stateLabel}</strong>{$emailNote}.";
                $flashType = 'success';
            }
        }
    }
}

// ── Load current overrides ────────────────────────────────────
$overrides = [];
if (file_exists($slotFile)) {
    $raw = file_get_contents($slotFile);
    $overrides = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?? []) : [];
}

// ── Load pending bookings (for modal popup) ───────────────────
$pendingFile = __DIR__ . '/pending-bookings.json';
$pendingData = [];
if (file_exists($pendingFile)) {
    $pbRaw = file_get_contents($pendingFile);
    $pendingData = ($pbRaw !== false && $pbRaw !== '') ? (json_decode($pbRaw, true) ?? []) : [];
}
// Build JS-safe map: slotKey → student first name
$pendingForJs = [];
foreach ($pendingData as $sk => $info) {
    if (is_array($info)) {
        $fullName = $info['name'] ?? 'the student';
        $pendingForJs[$sk] = strtok($fullName, ' ') ?: $fullName; // first name only
    }
}

function getState(string $dateStr, int $hour, int $dayOfWeek, array $overrides, array $base): string {
    return $overrides["{$dateStr}_{$hour}"] ?? ($base[$dayOfWeek][$hour] ?? 'unavailable');
}

// Build column dates (Mon–Sat)
$colDates = [];
for ($d = 0; $d < 6; $d++) {
    $colDates[$d] = $monday->modify("+{$d} days");
}

// Stats for this week only
$totalOpen = $totalBooked = $totalUnavail = 0;
foreach ($colDates as $d => $dt) {
    $dateStr = $dt->format('Y-m-d');
    foreach ($hours as $h) {
        $s = getState($dateStr, $h, $d, $overrides, $baseSchedule);
        if ($s === 'available')   $totalOpen++;
        elseif ($s === 'booked')  $totalBooked++;
        else                      $totalUnavail++;
    }
}

$weekLabel   = $monday->format('M j') . ' – ' . $saturday->format('M j, Y');
$prevWeekUrl = 'manage-slots.php?' . http_build_query(['key' => $key, 'week' => $weekOffset - 1]);
$nextWeekUrl = 'manage-slots.php?' . http_build_query(['key' => $key, 'week' => $weekOffset + 1]);
$thisWeekUrl = 'manage-slots.php?' . http_build_query(['key' => $key, 'week' => 0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Slot Manager — JCR Music Studio</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:          #06091a;
      --bg-card:     #0e1830;
      --bg-card2:    #0b1225;
      --blue:        #3b82f6;
      --blue-light:  #60a5fa;
      --blue-dim:    #1d4ed8;
      --text:        #eef2ff;
      --text-sec:    #8ba3c7;
      --border:      rgba(59,130,246,0.18);
      --green:       #22c55e;
      --green-bg:    rgba(34,197,94,0.12);
      --green-bd:    rgba(34,197,94,0.30);
      --red:         #ef4444;
      --red-bg:      rgba(239,68,68,0.10);
      --red-bd:      rgba(239,68,68,0.28);
      --gray:        #475569;
      --gray-bg:     rgba(71,85,105,0.20);
      --gray-bd:     rgba(71,85,105,0.35);
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      min-height: 100vh;
      padding: 0 0 60px;
    }

    /* ── Header ── */
    .page-header {
      background: var(--bg-card2);
      border-bottom: 1px solid var(--border);
      padding: 20px 28px;
      display: flex; align-items: center; gap: 14px;
    }
    .logo-mark {
      width: 38px; height: 38px;
      background: var(--blue-dim); border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 14px; color: #fff; letter-spacing: 1px; flex-shrink: 0;
    }
    .page-header h1 { font-size: 18px; font-weight: 700; color: var(--text); }
    .page-header p  { font-size: 13px; color: var(--text-sec); margin-top: 2px; }

    .content { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }

    /* ── Flash ── */
    .flash { padding: 13px 18px; border-radius: 8px; font-size: 14px; margin-bottom: 24px; border: 1px solid; }
    .flash.success { background: var(--green-bg); border-color: var(--green-bd); color: var(--green); }
    .flash.error   { background: var(--red-bg);   border-color: var(--red-bd);   color: var(--red);   }

    /* ── Week nav ── */
    .week-nav { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
    .week-nav-label { font-size: 18px; font-weight: 700; color: var(--text); flex: 1; min-width: 160px; }
    .week-nav-label small { display: block; font-size: 12px; font-weight: 400; color: var(--text-sec); margin-top: 2px; }
    .nav-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 7px; color: var(--text-sec); text-decoration: none;
      font-size: 13px; font-weight: 600; transition: border-color 0.15s, color 0.15s; white-space: nowrap;
    }
    .nav-btn:hover { border-color: var(--blue); color: var(--blue-light); }
    .nav-btn.today { color: var(--blue-light); border-color: rgba(59,130,246,0.35); }

    /* ── Stats ── */
    .stats { display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
    .stat { flex: 1; min-width: 110px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; text-align: center; }
    .stat-num { font-size: 28px; font-weight: 800; line-height: 1; }
    .stat-lbl { font-size: 11px; color: var(--text-sec); margin-top: 5px; text-transform: uppercase; letter-spacing: 0.8px; }
    .stat.open    .stat-num { color: var(--green); }
    .stat.booked  .stat-num { color: var(--blue-light); }
    .stat.unavail .stat-num { color: var(--gray); }

    /* ── Legend ── */
    .legend { display: flex; gap: 20px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
    .legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-sec); }
    .legend-dot  { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
    .dot-open    { background: var(--green); }
    .dot-booked  { background: var(--blue); }
    .dot-unavail { background: var(--gray); }

    /* ── Table ── */
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; }
    table { width: 100%; border-collapse: separate; border-spacing: 4px; min-width: 600px; }
    th {
      background: var(--bg-card2); color: var(--text-sec);
      font-size: 12px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase;
      padding: 10px 6px; text-align: center; border-radius: 6px; line-height: 1.4;
    }
    th .th-date { font-size: 11px; font-weight: 400; opacity: 0.7; display: block; margin-top: 2px; }
    th.time-col { text-align: right; padding-right: 12px; min-width: 64px; }

    /* ── Slot cells ── */
    .slot-cell { border-radius: 8px; padding: 8px 5px; text-align: center; vertical-align: middle; border: 2px solid transparent; position: relative; }
    .slot-cell.open    { background: var(--green-bg);  border-color: var(--green-bd); }
    .slot-cell.booked  { background: rgba(59,130,246,0.10); border-color: rgba(59,130,246,0.25); }
    .slot-cell.unavail { background: var(--gray-bg);   border-color: var(--gray-bd);  }

    /* Pending badge */
    .pending-dot {
      position: absolute; top: 5px; right: 5px;
      width: 7px; height: 7px; border-radius: 50%;
      background: #f59e0b;
      box-shadow: 0 0 0 2px rgba(245,158,11,0.25);
    }

    .slot-state-label { font-size: 10px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; margin-bottom: 5px; }
    .slot-cell.open    .slot-state-label { color: var(--green); }
    .slot-cell.booked  .slot-state-label { color: var(--blue-light); }
    .slot-cell.unavail .slot-state-label { color: var(--gray); }

    .slot-btns { display: flex; gap: 3px; justify-content: center; flex-wrap: wrap; }

    .slot-btn {
      display: inline-block; font-size: 10px; font-weight: 600; padding: 3px 7px;
      border-radius: 4px; text-decoration: none; border: 1px solid; cursor: pointer;
      line-height: 1.4; transition: opacity 0.15s, transform 0.1s; white-space: nowrap;
    }
    .slot-btn:hover  { opacity: 0.85; transform: translateY(-1px); }
    .slot-btn:active { transform: translateY(0); }

    .btn-open.active    { background: var(--green); border-color: var(--green); color: #fff; }
    .btn-booked.active  { background: var(--blue);  border-color: var(--blue);  color: #fff; }
    .btn-unavail.active { background: var(--gray);  border-color: var(--gray);  color: #fff; }

    .btn-open:not(.active)    { background: transparent; border-color: var(--green-bd);       color: var(--green); }
    .btn-booked:not(.active)  { background: transparent; border-color: rgba(59,130,246,0.35); color: var(--blue-light); }
    .btn-unavail:not(.active) { background: transparent; border-color: var(--gray-bd);        color: var(--gray); }

    .time-label { font-size: 12px; color: var(--text-sec); text-align: right; padding-right: 10px; font-weight: 500; white-space: nowrap; }

    .footer-note { margin-top: 32px; font-size: 12px; color: var(--text-sec); opacity: 0.5; text-align: center; }

    /* ── Modal overlay ── */
    .modal-overlay {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.72);
      align-items: center; justify-content: center;
      z-index: 1000; padding: 20px;
    }
    .modal-overlay.visible { display: flex; }

    .modal-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 32px 28px 28px;
      max-width: 420px; width: 100%;
      position: relative;
      box-shadow: 0 24px 60px rgba(0,0,0,0.5);
      animation: modalIn 0.18s ease;
    }
    @keyframes modalIn { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:none; } }

    .modal-icon {
      width: 48px; height: 48px;
      background: rgba(245,158,11,0.12);
      border: 1px solid rgba(245,158,11,0.3);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 22px;
      margin: 0 auto 20px;
    }
    .modal-title { font-size: 18px; font-weight: 700; text-align: center; margin-bottom: 12px; }
    .modal-body  { font-size: 14px; color: var(--text-sec); text-align: center; line-height: 1.7; margin-bottom: 24px; }
    .modal-body strong { color: var(--text); }

    .modal-actions { display: flex; gap: 10px; flex-direction: column; }
    .modal-btn {
      width: 100%; padding: 12px; border-radius: 8px; border: none;
      font-size: 14px; font-weight: 700; cursor: pointer;
      transition: opacity 0.15s, transform 0.1s;
    }
    .modal-btn:hover  { opacity: 0.88; transform: translateY(-1px); }
    .modal-btn:active { transform: translateY(0); }
    .modal-btn.primary   { background: var(--blue);  color: #fff; }
    .modal-btn.secondary { background: var(--bg-card2); color: var(--text-sec); border: 1px solid var(--border); }

    .modal-close {
      position: absolute; top: 14px; right: 16px;
      background: none; border: none; color: var(--text-sec);
      font-size: 18px; cursor: pointer; padding: 4px;
      line-height: 1; opacity: 0.6; transition: opacity 0.15s;
    }
    .modal-close:hover { opacity: 1; }

    /* ── Legend for pending dot ── */
    .pending-legend { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--text-sec); }
    .pending-legend-dot { width: 9px; height: 9px; border-radius: 50%; background: #f59e0b; }

    @media (max-width: 480px) {
      .page-header { padding: 16px; }
      .content { padding: 16px 12px; }
      .stat { padding: 12px; }
      .stat-num { font-size: 22px; }
      .week-nav-label { font-size: 15px; }
    }
  </style>
</head>
<body>

<header class="page-header">
  <div class="logo-mark">JCR</div>
  <div>
    <h1>Availability Manager</h1>
    <p>JCR Music Studio &mdash; Lesson Slot Control Panel</p>
  </div>
</header>

<div class="content">

  <?php if ($flashMsg): ?>
  <div class="flash <?= $flashType ?>">
    <?= $flashMsg ?>
  </div>
  <?php endif; ?>

  <!-- Week navigation -->
  <div class="week-nav">
    <div class="week-nav-label">
      <?= htmlspecialchars($weekLabel) ?>
      <?php if ($weekOffset === 0): ?><small>Current week</small>
      <?php elseif ($weekOffset < 0): ?><small><?= abs($weekOffset) ?> week<?= abs($weekOffset)>1?'s':'' ?> ago</small>
      <?php else: ?><small><?= $weekOffset ?> week<?= $weekOffset>1?'s':'' ?> ahead</small>
      <?php endif; ?>
    </div>
    <a href="<?= htmlspecialchars($prevWeekUrl) ?>" class="nav-btn">&#8592; Previous</a>
    <?php if ($weekOffset !== 0): ?>
    <a href="<?= htmlspecialchars($thisWeekUrl) ?>" class="nav-btn today">Today</a>
    <?php endif; ?>
    <a href="<?= htmlspecialchars($nextWeekUrl) ?>" class="nav-btn">Next &#8594;</a>
  </div>

  <!-- Stats -->
  <div class="stats">
    <div class="stat open">
      <div class="stat-num"><?= $totalOpen ?></div>
      <div class="stat-lbl">Open</div>
    </div>
    <div class="stat booked">
      <div class="stat-num"><?= $totalBooked ?></div>
      <div class="stat-lbl">Booked</div>
    </div>
    <div class="stat unavail">
      <div class="stat-num"><?= $totalUnavail ?></div>
      <div class="stat-lbl">Unavailable</div>
    </div>
  </div>

  <!-- Legend -->
  <div class="legend">
    <div class="legend-item"><div class="legend-dot dot-open"></div> Open — can book</div>
    <div class="legend-item"><div class="legend-dot dot-booked"></div> Booked — taken</div>
    <div class="legend-item"><div class="legend-dot dot-unavail"></div> N/A — blocked</div>
    <?php if (!empty($pendingForJs)): ?>
    <div class="legend-item pending-legend"><div class="pending-legend-dot"></div> Pending student inquiry</div>
    <?php endif; ?>
  </div>

  <!-- Schedule grid -->
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th class="time-col"></th>
          <?php foreach ($colDates as $d => $dt): ?>
          <th>
            <?= $dt->format('D') ?>
            <span class="th-date"><?= $dt->format('M j') ?></span>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($hours as $h): ?>
        <tr>
          <td class="time-label"><?= fmtHour($h) ?></td>
          <?php foreach ($colDates as $d => $dt):
            $dateStr    = $dt->format('Y-m-d');
            $slotKey    = "{$dateStr}_{$h}";
            $state      = getState($dateStr, $h, $d, $overrides, $baseSchedule);
            $hasPending = isset($pendingForJs[$slotKey]);
            $cellClass  = match($state) {
              'available'   => 'open',
              'booked'      => 'booked',
              default       => 'unavail',
            };
            $stateLabel = match($state) {
              'available'   => 'Open',
              'booked'      => 'Booked',
              default       => 'N/A',
            };
            $slotKeyAttr = htmlspecialchars($slotKey);
            $mkUrl = fn(string $s) => 'manage-slots.php?' . http_build_query([
              'key'   => $key,
              'week'  => $weekOffset,
              'slot'  => $slotKey,
              'state' => $s,
            ]);
          ?>
          <td class="slot-cell <?= $cellClass ?>">
            <?php if ($hasPending): ?><span class="pending-dot" title="Pending inquiry from <?= htmlspecialchars($pendingForJs[$slotKey]) ?>"></span><?php endif; ?>
            <div class="slot-state-label"><?= $stateLabel ?></div>
            <div class="slot-btns">
              <a href="<?= htmlspecialchars($mkUrl('available')) ?>"
                 class="slot-btn btn-open <?= $state==='available' ? 'active' : '' ?>"
                 data-slot="<?= $slotKeyAttr ?>">Open</a>
              <a href="<?= htmlspecialchars($mkUrl('booked')) ?>"
                 class="slot-btn btn-booked <?= $state==='booked' ? 'active' : '' ?>"
                 data-slot="<?= $slotKeyAttr ?>">Booked</a>
              <a href="<?= htmlspecialchars($mkUrl('unavailable')) ?>"
                 class="slot-btn btn-unavail <?= $state==='unavailable' ? 'active' : '' ?>"
                 data-slot="<?= $slotKeyAttr ?>">N/A</a>
            </div>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="footer-note">Bookmark this page to always have access. Keep the URL private — anyone with this link can edit slots.</p>

</div>

<!-- ── Notify modal ── -->
<div id="notify-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="modal-card">
    <button id="modal-dismiss" class="modal-close" aria-label="Dismiss">&#10005;</button>
    <div class="modal-icon">&#128276;</div>
    <h2 class="modal-title" id="modal-title">Notify Student?</h2>
    <p class="modal-body">
      <strong id="modal-student-name"></strong> has a pending inquiry for this slot.<br>
      Would you like to send them an email about this update?
    </p>
    <div class="modal-actions">
      <button id="modal-yes" class="modal-btn primary">Yes, Email Them</button>
      <button id="modal-no"  class="modal-btn secondary">No, Just Update</button>
    </div>
  </div>
</div>

<script>
  // Pending bookings data from PHP (slotKey → student first name)
  const pendingBookings = <?= json_encode($pendingForJs, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  const modal      = document.getElementById('notify-modal');
  const modalName  = document.getElementById('modal-student-name');
  const modalYes   = document.getElementById('modal-yes');
  const modalNo    = document.getElementById('modal-no');
  const modalDismiss = document.getElementById('modal-dismiss');

  let pendingHref = '';

  function showModal(studentName, href) {
    pendingHref = href;
    modalName.textContent = studentName;
    modal.classList.add('visible');
    modalYes.focus();
  }

  function hideModal() {
    modal.classList.remove('visible');
    pendingHref = '';
  }

  // Intercept slot button clicks
  document.querySelectorAll('.slot-btn').forEach(link => {
    link.addEventListener('click', function(e) {
      if (this.classList.contains('active')) return; // no change — let it go
      const slot = this.dataset.slot;
      if (slot && pendingBookings[slot] !== undefined) {
        e.preventDefault();
        showModal(pendingBookings[slot], this.href);
      }
    });
  });

  modalYes.addEventListener('click', () => {
    if (pendingHref) window.location.href = pendingHref + '&notify=1';
    hideModal();
  });

  modalNo.addEventListener('click', () => {
    if (pendingHref) window.location.href = pendingHref + '&notify=0';
    hideModal();
  });

  modalDismiss.addEventListener('click', hideModal);

  // Close on backdrop click
  modal.addEventListener('click', e => { if (e.target === modal) hideModal(); });

  // Close on Escape
  document.addEventListener('keydown', e => { if (e.key === 'Escape') hideModal(); });
</script>
</body>
</html>
