<?php
/**
 * Slot Manager — Secret Admin Page
 * Access: /php/manage-slots.php?key=YOUR_MANAGE_KEY
 *
 * Lets the site owner view and change any lesson slot state
 * without needing an email. Changes sync instantly with the
 * booking calendar and the email action buttons (same JSON file).
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

// ── Base schedule (mirrors js/main.js) ────────────────────────
$baseSchedule = [
    0 => [ 9=>'booked',10=>'available',11=>'available',12=>'unavailable',13=>'unavailable',14=>'booked',15=>'available',16=>'available',17=>'available',18=>'booked',19=>'unavailable' ],
    1 => [ 9=>'available',10=>'available',11=>'booked',12=>'unavailable',13=>'unavailable',14=>'available',15=>'booked',16=>'available',17=>'booked',18=>'available',19=>'available' ],
    2 => [ 9=>'unavailable',10=>'booked',11=>'booked',12=>'booked',13=>'unavailable',14=>'available',15=>'available',16=>'booked',17=>'available',18=>'available',19=>'unavailable' ],
    3 => [ 9=>'available',10=>'booked',11=>'available',12=>'unavailable',13=>'unavailable',14=>'available',15=>'available',16=>'booked',17=>'available',18=>'booked',19=>'available' ],
    4 => [ 9=>'booked',10=>'booked',11=>'available',12=>'unavailable',13=>'unavailable',14=>'booked',15=>'available',16=>'available',17=>'booked',18=>'available',19=>'available' ],
    5 => [ 9=>'available',10=>'available',11=>'booked',12=>'available',13=>'available',14=>'booked',15=>'available',16=>'unavailable',17=>'unavailable',18=>'unavailable',19=>'unavailable' ],
];
$hours    = [9,10,11,12,13,14,15,16,17,18,19];
$dayNames = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$dayShort = ['Mon','Tue','Wed','Thu','Fri','Sat'];

function fmtHour(int $h): string {
    if ($h === 12) return '12 PM';
    return $h < 12 ? "{$h} AM" : ($h - 12) . ' PM';
}

// ── Handle state update ───────────────────────────────────────
$flashMsg  = '';
$flashType = '';

$slotFile   = __DIR__ . '/slot-states.json';
$validStates = ['available', 'booked', 'unavailable'];

if (isset($_GET['slot'], $_GET['state'])) {
    $updSlot  = $_GET['slot'];
    $updState = $_GET['state'];

    if (
        in_array($updState, $validStates, true) &&
        preg_match('/^\d+_\d+$/', $updSlot)
    ) {
        $overrides = [];
        if (file_exists($slotFile)) {
            $raw = file_get_contents($slotFile);
            $overrides = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?? []) : [];
        }

        $overrides[$updSlot] = $updState;
        $written = file_put_contents($slotFile, json_encode($overrides, JSON_PRETTY_PRINT), LOCK_EX);

        // Parse slot for readable label
        [$dIdx, $hIdx] = explode('_', $updSlot, 2);
        $slotLabel = ($dayNames[(int)$dIdx] ?? 'Unknown') . ' at ' . fmtHour((int)$hIdx);
        $stateLabel = match($updState) {
            'available'   => 'Open',
            'booked'      => 'Booked',
            'unavailable' => 'Unavailable',
        };

        if ($written !== false) {
            $flashMsg  = "&#10003; &nbsp; <strong>{$slotLabel}</strong> updated to <strong>{$stateLabel}</strong>.";
            $flashType = 'success';
        } else {
            $flashMsg  = "&#10007; &nbsp; Could not save changes. Check that the <code>php/</code> folder is writable on the server.";
            $flashType = 'error';
        }

        // Redirect to remove ?slot&state from URL (prevents re-submit on refresh)
        $qs = http_build_query(['key' => $key, 'flash' => $updSlot . ':' . $updState]);
        header("Location: manage-slots.php?{$qs}");
        exit;
    }
}

// Restore flash message from redirect
if (!$flashMsg && isset($_GET['flash'])) {
    $parts = explode(':', $_GET['flash'], 2);
    if (count($parts) === 2 && in_array($parts[1], $validStates, true)) {
        [$dIdx, $hIdx] = explode('_', $parts[0], 2);
        $slotLabel  = ($dayNames[(int)$dIdx] ?? '?') . ' at ' . fmtHour((int)$hIdx);
        $stateLabel = match($parts[1]) {
            'available'   => 'Open',
            'booked'      => 'Booked',
            'unavailable' => 'Unavailable',
            default       => $parts[1],
        };
        $flashMsg  = "&#10003; &nbsp; <strong>{$slotLabel}</strong> updated to <strong>{$stateLabel}</strong>.";
        $flashType = 'success';
    }
}

// ── Load current overrides ────────────────────────────────────
$overrides = [];
if (file_exists($slotFile)) {
    $raw = file_get_contents($slotFile);
    $overrides = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?? []) : [];
}

function getState(int $day, int $hour, array $overrides, array $base): string {
    return $overrides["{$day}_{$hour}"] ?? ($base[$day][$hour] ?? 'unavailable');
}

// Count stats
$totalOpen     = 0;
$totalBooked   = 0;
$totalUnavail  = 0;
foreach (range(0,5) as $d) {
    foreach ([9,10,11,12,13,14,15,16,17,18,19] as $h) {
        $s = getState($d, $h, $overrides, $baseSchedule);
        if ($s === 'available')   $totalOpen++;
        elseif ($s === 'booked')  $totalBooked++;
        else                      $totalUnavail++;
    }
}

$keyEncoded = htmlspecialchars($key, ENT_QUOTES);
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
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .logo-mark {
      width: 38px; height: 38px;
      background: var(--blue-dim);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 14px; color: #fff; letter-spacing: 1px;
      flex-shrink: 0;
    }
    .page-header h1 { font-size: 18px; font-weight: 700; color: var(--text); }
    .page-header p  { font-size: 13px; color: var(--text-sec); margin-top: 2px; }

    /* ── Main content ── */
    .content { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }

    /* ── Flash banner ── */
    .flash {
      padding: 13px 18px;
      border-radius: 8px;
      font-size: 14px;
      margin-bottom: 24px;
      border: 1px solid;
    }
    .flash.success { background: var(--green-bg); border-color: var(--green-bd); color: var(--green); }
    .flash.error   { background: var(--red-bg);   border-color: var(--red-bd);   color: var(--red);   }

    /* ── Stats row ── */
    .stats {
      display: flex; gap: 12px; margin-bottom: 28px; flex-wrap: wrap;
    }
    .stat {
      flex: 1; min-width: 130px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 16px 20px;
      text-align: center;
    }
    .stat-num  { font-size: 32px; font-weight: 800; line-height: 1; }
    .stat-lbl  { font-size: 12px; color: var(--text-sec); margin-top: 6px; text-transform: uppercase; letter-spacing: 0.8px; }
    .stat.open  .stat-num { color: var(--green); }
    .stat.booked .stat-num { color: var(--blue-light); }
    .stat.unavail .stat-num { color: var(--gray); }

    /* ── Legend ── */
    .legend {
      display: flex; gap: 20px; align-items: center;
      margin-bottom: 20px; flex-wrap: wrap;
    }
    .legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-sec); }
    .legend-dot  { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
    .dot-open    { background: var(--green); }
    .dot-booked  { background: var(--blue); }
    .dot-unavail { background: var(--gray); }

    /* ── Instruction note ── */
    .tip {
      background: rgba(59,130,246,0.07);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px 16px;
      font-size: 13px;
      color: var(--text-sec);
      margin-bottom: 24px;
      line-height: 1.6;
    }
    .tip strong { color: var(--blue-light); }

    /* ── Scroll wrapper ── */
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; }

    /* ── Grid table ── */
    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 4px;
      min-width: 600px;
    }

    th {
      background: var(--bg-card2);
      color: var(--text-sec);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.8px;
      text-transform: uppercase;
      padding: 10px 6px;
      text-align: center;
      border-radius: 6px;
    }
    th.time-col { color: var(--text-sec); font-weight: 600; text-align: right; padding-right: 12px; min-width: 64px; }

    /* ── Slot cells ── */
    .slot-cell {
      border-radius: 8px;
      padding: 8px 6px;
      text-align: center;
      vertical-align: middle;
      border: 2px solid transparent;
      transition: border-color 0.15s;
    }
    .slot-cell.open    { background: var(--green-bg);  border-color: var(--green-bd); }
    .slot-cell.booked  { background: rgba(59,130,246,0.10); border-color: rgba(59,130,246,0.25); }
    .slot-cell.unavail { background: var(--gray-bg);   border-color: var(--gray-bd);  }

    .slot-state-label {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.6px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .slot-cell.open    .slot-state-label { color: var(--green); }
    .slot-cell.booked  .slot-state-label { color: var(--blue-light); }
    .slot-cell.unavail .slot-state-label { color: var(--gray); }

    .slot-btns { display: flex; gap: 3px; justify-content: center; flex-wrap: wrap; }

    .slot-btn {
      display: inline-block;
      font-size: 10px;
      font-weight: 600;
      padding: 3px 7px;
      border-radius: 4px;
      text-decoration: none;
      border: 1px solid;
      cursor: pointer;
      line-height: 1.4;
      transition: opacity 0.15s, transform 0.1s;
      white-space: nowrap;
    }
    .slot-btn:hover   { opacity: 0.85; transform: translateY(-1px); }
    .slot-btn:active  { transform: translateY(0); }

    /* Active (current state) button — solid fill */
    .btn-open.active    { background: var(--green);  border-color: var(--green);  color: #fff; }
    .btn-booked.active  { background: var(--blue);   border-color: var(--blue);   color: #fff; }
    .btn-unavail.active { background: var(--gray);   border-color: var(--gray);   color: #fff; }

    /* Inactive buttons — ghost style */
    .btn-open:not(.active)    { background: transparent; border-color: var(--green-bd);  color: var(--green); }
    .btn-booked:not(.active)  { background: transparent; border-color: rgba(59,130,246,0.35); color: var(--blue-light); }
    .btn-unavail:not(.active) { background: transparent; border-color: var(--gray-bd);  color: var(--gray); }

    .time-label { font-size: 12px; color: var(--text-sec); text-align: right; padding-right: 10px; font-weight: 500; white-space: nowrap; }

    /* ── Footer note ── */
    .footer-note {
      margin-top: 32px;
      font-size: 12px;
      color: var(--text-sec);
      opacity: 0.6;
      text-align: center;
    }

    @media (max-width: 480px) {
      .page-header { padding: 16px; }
      .content { padding: 16px 12px; }
      .stat { padding: 12px; }
      .stat-num { font-size: 26px; }
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

  <!-- Stats -->
  <div class="stats">
    <div class="stat open">
      <div class="stat-num"><?= $totalOpen ?></div>
      <div class="stat-lbl">Open Slots</div>
    </div>
    <div class="stat booked">
      <div class="stat-num"><?= $totalBooked ?></div>
      <div class="stat-lbl">Booked Slots</div>
    </div>
    <div class="stat unavail">
      <div class="stat-num"><?= $totalUnavail ?></div>
      <div class="stat-lbl">Unavailable Slots</div>
    </div>
  </div>

  <!-- Tip -->
  <div class="tip">
    <strong>How to use:</strong> Click any button below a time slot to instantly change its state.
    Changes are reflected on the booking calendar immediately &mdash; no page reload needed for visitors.
    This page also stays in sync with any buttons you click in your email notifications.
  </div>

  <!-- Legend -->
  <div class="legend">
    <div class="legend-item"><div class="legend-dot dot-open"></div> Open &mdash; student can book this slot</div>
    <div class="legend-item"><div class="legend-dot dot-booked"></div> Booked &mdash; slot is taken</div>
    <div class="legend-item"><div class="legend-dot dot-unavail"></div> Unavailable &mdash; slot is blocked off</div>
  </div>

  <!-- Schedule grid -->
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th class="time-col">Time</th>
          <?php foreach ($dayShort as $d): ?>
          <th><?= $d ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($hours as $h): ?>
        <tr>
          <td class="time-label"><?= fmtHour($h) ?></td>
          <?php foreach (range(0,5) as $d):
            $state      = getState($d, $h, $overrides, $baseSchedule);
            $cellClass  = match($state) {
              'available'   => 'open',
              'booked'      => 'booked',
              default       => 'unavail',
            };
            $stateLabel = match($state) {
              'available'   => 'Open',
              'booked'      => 'Booked',
              default       => 'Unavailable',
            };
            $slotKey = "{$d}_{$h}";
            $mkUrl = fn(string $s) => "manage-slots.php?" . http_build_query(['key'=>$key,'slot'=>$slotKey,'state'=>$s]);
          ?>
          <td class="slot-cell <?= $cellClass ?>">
            <div class="slot-state-label"><?= $stateLabel ?></div>
            <div class="slot-btns">
              <a href="<?= htmlspecialchars($mkUrl('available')) ?>"
                 class="slot-btn btn-open <?= $state==='available' ? 'active' : '' ?>"
                 title="Mark <?= $dayNames[$d] ?> <?= fmtHour($h) ?> as Open">Open</a>
              <a href="<?= htmlspecialchars($mkUrl('booked')) ?>"
                 class="slot-btn btn-booked <?= $state==='booked' ? 'active' : '' ?>"
                 title="Mark <?= $dayNames[$d] ?> <?= fmtHour($h) ?> as Booked">Booked</a>
              <a href="<?= htmlspecialchars($mkUrl('unavailable')) ?>"
                 class="slot-btn btn-unavail <?= $state==='unavailable' ? 'active' : '' ?>"
                 title="Mark <?= $dayNames[$d] ?> <?= fmtHour($h) ?> as Unavailable">N/A</a>
            </div>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="footer-note">Bookmark this page to always have access. Keep the URL private &mdash; anyone with this link can edit slots.</p>

</div>
</body>
</html>
