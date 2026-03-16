<?php
/**
 * Guitar Lessons — Contact Form Handler
 * Validates input, applies spam protection, sends two emails via PHPMailer + Yahoo SMTP:
 *   1. Notification to the studio (MAIL_TO)
 *   2. Confirmation to the form submitter
 *
 * Requirements: PHP 7.4+, composer install, .env filled in.
 */

declare(strict_types=1);

// Suppress HTML error output so PHP errors don't break the JSON response
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Catch fatal errors and return them as JSON instead of HTML
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'message' => 'A server error occurred.',
            'error'   => $error['message'],
            'file'    => basename($error['file']),
            'line'    => $error['line'],
        ]);
    }
});

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Only allow POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Rate limiting (session-based) ────────────────────────────
session_start();
$now = time();
if (!isset($_SESSION['last_submit']))  $_SESSION['last_submit']  = 0;
if (!isset($_SESSION['submit_count'])) $_SESSION['submit_count'] = 0;

if ($now - $_SESSION['last_submit'] > 600) {
    $_SESSION['submit_count'] = 0;
}
$rateLimit = (int) ($_ENV['MAIL_DEBUG'] ?? 0) >= 1 ? 100 : 5;
if ($_SESSION['submit_count'] >= $rateLimit) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again in a few minutes.']);
    exit;
}

// ── Honeypot ──────────────────────────────────────────────────
if (trim($_POST['website'] ?? '') !== '') {
    echo json_encode(['success' => true, 'message' => "Thank you! I'll be in touch soon."]);
    exit;
}

// ── Helper ────────────────────────────────────────────────────
function sanitizeText(string $input): string {
    $clean = strip_tags(trim($input));
    $clean = htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return preg_replace('/[\r\n\t]/', ' ', $clean);
}

// ── Collect & sanitize ────────────────────────────────────────
$name          = sanitizeText($_POST['name']           ?? '');
$emailRaw      = trim($_POST['email']                  ?? '');
$phone         = sanitizeText($_POST['phone']          ?? '');
$lessonType    = sanitizeText($_POST['lesson_type']    ?? '');
$preferredTime = sanitizeText($_POST['preferred_time'] ?? '');
$rawSlotKey    = sanitizeText($_POST['slot_key']       ?? '');
$experience    = sanitizeText($_POST['experience']     ?? '');
$messageRaw    = trim($_POST['message']                ?? '');

$email   = filter_var($emailRaw, FILTER_SANITIZE_EMAIL);
$message = htmlspecialchars(strip_tags($messageRaw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ── Validate ──────────────────────────────────────────────────
$errors = [];

if (empty($name)) {
    $errors[] = 'Full name is required.';
} elseif (mb_strlen($name) > 100) {
    $errors[] = 'Name must be under 100 characters.';
}

if (empty($email)) {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
} elseif (mb_strlen($email) > 254) {
    $errors[] = 'Email address is too long.';
}

if (!empty($phone) && !preg_match('/^[\d\s\-\+\(\)\.]{7,20}$/', $phone)) {
    $errors[] = 'Phone number format is invalid.';
}

$validLessonTypes = ['beginner','intermediate','advanced','theory','classical','songwriting','unsure',''];
if (!in_array($lessonType, $validLessonTypes, true)) {
    $errors[] = 'Please select a valid lesson type.';
}

if (empty($message)) {
    $errors[] = 'A message is required.';
} elseif (mb_strlen($message) < 10) {
    $errors[] = 'Message is too short (minimum 10 characters).';
} elseif (mb_strlen($message) > 5000) {
    $errors[] = 'Message is too long (maximum 5000 characters).';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// ── Load PHPMailer & config ────────────────────────────────────
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mailer not configured. Please run: composer install']);
    exit;
}

require_once __DIR__ . '/mailer-config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Resolve slot key ──────────────────────────────────────────
// slot_key format: "YYYY-MM-DD_H"  e.g. "2026-03-16_9"
$slotKey         = null;
$slotActionBlock = '';

if ($rawSlotKey !== '') {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{1,2})$/', $rawSlotKey, $m)) {
        $dt   = DateTimeImmutable::createFromFormat('Y-m-d', $m[1]);
        $hour = (int)$m[2];
        if ($dt && $dt->format('Y-m-d') === $m[1] && $hour >= 0 && $hour <= 23) {
            $slotKey = $rawSlotKey;
        }
    }
}

// ── Persist pending booking so student can be notified later ─
if ($slotKey !== null) {
    $pbFile  = __DIR__ . '/pending-bookings.json';
    $pb      = [];
    if (file_exists($pbFile)) {
        $pbRaw = file_get_contents($pbFile);
        $pb    = ($pbRaw !== false && $pbRaw !== '') ? (json_decode($pbRaw, true) ?? []) : [];
    }
    $pb[$slotKey] = [
        'name'           => $name,
        'email'          => $email,
        'preferred_time' => $preferredTime,
        'submitted_at'   => date('Y-m-d H:i:s'),
    ];
    file_put_contents($pbFile, json_encode($pb, JSON_PRETTY_PRINT), LOCK_EX);
}

if ($slotKey !== null) {
    $tokenAccept  = hash_hmac('sha256', "{$slotKey}:accept",  SLOT_SECRET);
    $tokenDecline = hash_hmac('sha256', "{$slotKey}:decline", SLOT_SECRET);
    $tokenCancel  = hash_hmac('sha256', "{$slotKey}:cancel",  SLOT_SECRET);
    $baseAction   = SITE_URL . '/php/slot-action.php';
    // HTML-encoded URLs for href attributes (&amp; required by HTML spec)
    $urlAccept    = "{$baseAction}?slot={$slotKey}&amp;action=accept&amp;token={$tokenAccept}";
    $urlDecline   = "{$baseAction}?slot={$slotKey}&amp;action=decline&amp;token={$tokenDecline}";
    $urlCancel    = "{$baseAction}?slot={$slotKey}&amp;action=cancel&amp;token={$tokenCancel}";

    $slotActionBlock = <<<HTML
  <div class="section">
    <h2>Respond to This Inquiry</h2>
    <p style="font-size:13px; color:#8a8a8a; margin:0 0 16px;">
      Requested slot: <strong style="color:#f0f0f0;">{$preferredTime}</strong>
    </p>
    <table role="presentation" style="border-collapse:collapse; width:100%;">
      <tr>
        <td style="padding:0 5px 0 0; width:33%;">
          <a href="{$urlAccept}" style="display:block; text-align:center; padding:13px 6px; background:#1f6e1f; color:#ffffff; text-decoration:none; border-radius:6px; font-size:13px; font-weight:bold; letter-spacing:0.5px;">&#10003; Accept</a>
        </td>
        <td style="padding:0 5px; width:33%;">
          <a href="{$urlDecline}" style="display:block; text-align:center; padding:13px 6px; background:#444; color:#ffffff; text-decoration:none; border-radius:6px; font-size:13px; font-weight:bold; letter-spacing:0.5px;">&#10007; Decline</a>
        </td>
        <td style="padding:0 0 0 5px; width:34%;">
          <a href="{$urlCancel}" style="display:block; text-align:center; padding:13px 6px; background:#7a1c1c; color:#ffffff; text-decoration:none; border-radius:6px; font-size:12px; font-weight:bold; letter-spacing:0.3px;">&#10007; Decline &amp; Cancel</a>
        </td>
      </tr>
    </table>
<p style="font-size:11px; color:#555; margin:10px 0 0; line-height:1.5;">
      Accept - marks slot <em>Booked</em> &nbsp;|&nbsp;
      Decline - reopens slot &nbsp;|&nbsp;
      Decline &amp; Cancel - marks slot <em>Unavailable</em>
    </p>
  </div>
HTML;
}

// ── Label maps ────────────────────────────────────────────────
$lessonLabel = [
    'beginner'     => 'Beginner Foundations',
    'intermediate' => 'Intermediate Development',
    'advanced'     => 'Advanced Technique',
    'theory'       => 'Music Theory',
    'classical'    => 'Classical Guitar',
    'songwriting'  => 'Songwriting',
    'unsure'       => 'Not sure yet',
    ''             => 'Not specified',
][$lessonType] ?? $lessonType;

$experienceLabel = [
    'none'         => 'No experience',
    'beginner'     => 'Beginner (0-1 year)',
    'intermediate' => 'Intermediate (1-3 years)',
    'advanced'     => 'Advanced (3+ years)',
    ''             => 'Not specified',
][$experience] ?? $experience;

// ── Shared email styles ────────────────────────────────────────
$emailStyles = <<<CSS
body { font-family: Georgia, serif; background: #080808; color: #f0f0f0; margin: 0; padding: 0; }
.wrap { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
.header { border-bottom: 2px solid #c9a84c; padding-bottom: 24px; margin-bottom: 32px; }
.logo { font-size: 28px; font-weight: bold; color: #c9a84c; letter-spacing: -1px; }
.subtitle { font-size: 12px; color: #8a8a8a; letter-spacing: 2px; text-transform: uppercase; margin-top: 4px; }
h2 { font-size: 18px; color: #c9a84c; margin: 0 0 16px; }
.field { margin-bottom: 12px; }
.label { font-size: 11px; color: #8a8a8a; letter-spacing: 1px; text-transform: uppercase; display: block; margin-bottom: 3px; }
.value { font-size: 15px; color: #f0f0f0; }
.message-box { background: #111; border-left: 3px solid #c9a84c; padding: 18px; margin-top: 8px; border-radius: 4px; line-height: 1.7; }
.section { background: #111; border-radius: 8px; padding: 22px; margin-bottom: 18px; }
.footer { margin-top: 36px; padding-top: 18px; border-top: 1px solid #222; font-size: 11px; color: #444; line-height: 1.6; }
a { color: #c9a84c; }
CSS;

// ── 1. Admin notification email ───────────────────────────────
$adminHtml = <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>{$emailStyles}</style></head>
<body><div class="wrap">
  <div class="header">
    <div class="logo">JCR Music Studio</div>
    <div class="subtitle">New Lesson Inquiry</div>
  </div>
  <div class="section">
    <h2>Contact Details</h2>
    <div class="field"><span class="label">Name</span><span class="value">{$name}</span></div>
    <div class="field"><span class="label">Email</span><span class="value"><a href="mailto:{$email}">{$email}</a></span></div>
    <div class="field"><span class="label">Phone</span><span class="value">{$phone}</span></div>
  </div>
  <div class="section">
    <h2>Lesson Details</h2>
    <div class="field"><span class="label">Program</span><span class="value">{$lessonLabel}</span></div>
    <div class="field"><span class="label">Experience Level</span><span class="value">{$experienceLabel}</span></div>
    <div class="field"><span class="label">Preferred Day &amp; Time</span><span class="value">{$preferredTime}</span></div>
  </div>
  <div class="section">
    <h2>Message</h2>
    <div class="message-box">{$message}</div>
  </div>
{$slotActionBlock}
  <div class="footer">
    <p>Reply directly to <a href="mailto:{$email}">{$email}</a> to respond to this inquiry.</p>
  </div>
</div></body></html>
HTML;

$adminPlain = "New lesson inquiry from {$name}\n\nName: {$name}\nEmail: {$email}\nPhone: {$phone}\nProgram: {$lessonLabel}\nExperience: {$experienceLabel}\nPreferred Time: {$preferredTime}\n\nMessage:\n{$message}";

// ── 2. User confirmation email ────────────────────────────────
$userHtml = <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>{$emailStyles}</style></head>
<body><div class="wrap">
  <div class="header">
    <div class="logo">JCR Music Studio</div>
    <div class="subtitle">We received your message</div>
  </div>
  <p style="font-size:16px; line-height:1.7; color:#d0d0d0; margin:0 0 28px;">
    Hi {$name}, thank you for reaching out! We've received your inquiry and will be in touch within <strong style="color:#c9a84c;">24 hours</strong> to discuss your goals and get you scheduled.
  </p>
  <div class="section">
    <h2>Your Submission</h2>
    <div class="field"><span class="label">Program Interest</span><span class="value">{$lessonLabel}</span></div>
    <div class="field"><span class="label">Experience Level</span><span class="value">{$experienceLabel}</span></div>
    <div class="field"><span class="label">Preferred Day &amp; Time</span><span class="value">{$preferredTime}</span></div>
    <div class="field"><span class="label">Your Message</span><div class="message-box">{$message}</div></div>
  </div>
  <div class="footer">
    <p>If you have any questions in the meantime, feel free to reply to this email.</p>
    <p style="margin-top:8px;">- JCR Music Studio</p>
  </div>
</div></body></html>
HTML;

$userPlain = "Hi {$name},\n\nThank you for reaching out to JCR Music Studio! We've received your inquiry and will be in touch within 24 hours.\n\nYour submission:\nProgram: {$lessonLabel}\nExperience: {$experienceLabel}\nPreferred Time: {$preferredTime}\n\nYour message:\n{$messageRaw}\n\n— JCR Music Studio";

// ── Send both emails ──────────────────────────────────────────
$smtpLog = [];

function buildMailer(): PHPMailer {
    global $smtpLog;
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->Timeout    = 15;
    $mail->Hostname   = 'thedomaindesigners.com';
    $mail->Sender     = SMTP_FROM; // Sets Return-Path to match From — fixes DMARC SPF alignment
    $mail->XMailer    = ' ';       // Suppress PHPMailer fingerprint header
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->isHTML(true);

    if (MAIL_DEBUG >= 1) {
        $mail->SMTPDebug  = 2; // capture full SMTP conversation
        $mail->Debugoutput = function (string $str) use (&$smtpLog): void {
            $smtpLog[] = trim($str);
        };
    }

    return $mail;
}

try {
    // Admin notification
    $admin = buildMailer();
    $admin->addAddress(MAIL_TO, MAIL_TO_NAME);
    $admin->addReplyTo($email, $name);
    $admin->Subject = "New Lesson Inquiry from {$name}";
    $admin->Body    = $adminHtml;
    $admin->AltBody = $adminPlain;
    $admin->send();

    // User confirmation — Reply-To set to studio's main address so replies land there
    $confirm = buildMailer();
    $confirm->addReplyTo(MAIL_TO, MAIL_TO_NAME);
    $confirm->addAddress($email, $name);
    $confirm->Subject = "JCR Music Studio - We received your message";
    $confirm->Body    = $userHtml;
    $confirm->AltBody = $userPlain;
    $confirm->send();

    $_SESSION['submit_count']++;
    $_SESSION['last_submit'] = $now;

    $response = [
        'success' => true,
        'message' => "Thank you, {$name}! Your message has been received. Check your inbox — a confirmation has been sent to {$email}.",
    ];
    if (MAIL_DEBUG >= 1) {
        $response['smtp_log']   = $smtpLog;
        $response['debug_slot'] = [
            'preferred_time'       => $preferredTime,
            'slot_key'             => $slotKey,
            'action_block_present' => $slotActionBlock !== '',
        ];
    }
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'success' => false,
        'message' => 'Failed to send your message. Please email us directly at ' . MAIL_TO,
        'error'   => $e->getMessage(),
    ];
    if (MAIL_DEBUG >= 1) $response['smtp_log'] = $smtpLog;
    echo json_encode($response);
}
