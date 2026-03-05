<?php
/**
 * Gary Coleman Guitar — Contact Form Handler
 * Validates input, applies basic spam protection, sends email.
 *
 * Requirements: PHP 7.4+, server with mail() configured.
 * For production, replace mail() with PHPMailer + SMTP.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Only allow POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── CORS (adjust origin to your domain) ─────────────────────
$allowedOrigins = ['http://localhost', 'https://yourdomain.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ── Rate limiting (session-based, basic) ─────────────────────
session_start();
$now = time();
if (!isset($_SESSION['last_submit'])) $_SESSION['last_submit'] = 0;
if (!isset($_SESSION['submit_count'])) $_SESSION['submit_count'] = 0;

// Reset count every 10 minutes
if ($now - $_SESSION['last_submit'] > 600) {
    $_SESSION['submit_count'] = 0;
}

if ($_SESSION['submit_count'] >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again in a few minutes.']);
    exit;
}

// ── Honeypot check ────────────────────────────────────────────
$honeypot = trim($_POST['website'] ?? '');
if ($honeypot !== '') {
    // Silently succeed to confuse bots
    echo json_encode(['success' => true, 'message' => "Thank you! I'll be in touch soon."]);
    exit;
}

// ── Helper: sanitize text ─────────────────────────────────────
function sanitizeText(string $input): string {
    $clean = trim($input);
    $clean = strip_tags($clean);
    $clean = htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Prevent header injection
    $clean = preg_replace('/[\r\n\t]/', ' ', $clean);
    return $clean;
}

// ── Collect & sanitize inputs ─────────────────────────────────
$name           = sanitizeText($_POST['name']           ?? '');
$emailRaw       = trim($_POST['email']                  ?? '');
$phone          = sanitizeText($_POST['phone']          ?? '');
$lessonType     = sanitizeText($_POST['lesson_type']    ?? '');
$preferredTime  = sanitizeText($_POST['preferred_time'] ?? '');
$experience     = sanitizeText($_POST['experience']     ?? '');
$messageRaw     = trim($_POST['message']                ?? '');

// Sanitize email separately
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

$validLessonTypes = ['beginner', 'intermediate', 'advanced', 'theory', 'classical', 'songwriting', 'unsure', ''];
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

// ── Build email ───────────────────────────────────────────────
$recipientEmail = 'gary@garycolemanguitar.com'; // ← Update to real email
$recipientName  = 'Gary Coleman';
$senderDomain   = $_SERVER['HTTP_HOST'] ?? 'garycolemanguitar.com';

$subject = "New Lesson Inquiry from {$name}";

// Plain text body
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
    'beginner'     => 'Beginner (0–1 year)',
    'intermediate' => 'Intermediate (1–3 years)',
    'advanced'     => 'Advanced (3+ years)',
    ''             => 'Not specified',
][$experience] ?? $experience;

$plainBody = <<<TEXT
New lesson inquiry received via garycolemanguitar.com

──────────────────────────────────────────
CONTACT DETAILS
──────────────────────────────────────────
Name:            {$name}
Email:           {$email}
Phone:           {$phone}

──────────────────────────────────────────
LESSON DETAILS
──────────────────────────────────────────
Program:         {$lessonLabel}
Experience:      {$experienceLabel}
Preferred Time:  {$preferredTime}

──────────────────────────────────────────
MESSAGE
──────────────────────────────────────────
{$message}

──────────────────────────────────────────
Submitted: {$_SERVER['HTTP_HOST']} — {$now}
TEXT;

// HTML body
$htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Georgia, serif; background: #080808; color: #f0f0f0; margin: 0; padding: 0; }
  .wrap { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
  .header { border-bottom: 2px solid #c9a84c; padding-bottom: 24px; margin-bottom: 32px; }
  .logo { font-size: 32px; font-weight: bold; color: #c9a84c; letter-spacing: -1px; }
  .subtitle { font-size: 12px; color: #8a8a8a; letter-spacing: 2px; text-transform: uppercase; margin-top: 4px; }
  h2 { font-size: 20px; color: #c9a84c; margin: 0 0 20px; }
  .field { margin-bottom: 12px; }
  .label { font-size: 11px; color: #8a8a8a; letter-spacing: 1px; text-transform: uppercase; display: block; margin-bottom: 4px; }
  .value { font-size: 15px; color: #f0f0f0; }
  .message-box { background: #111; border-left: 3px solid #c9a84c; padding: 20px; margin-top: 8px; border-radius: 4px; line-height: 1.7; }
  .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #222; font-size: 11px; color: #444; }
  .section { background: #111; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <div class="logo">Gary Coleman</div>
    <div class="subtitle">Guitar Instructor — New Inquiry</div>
  </div>

  <div class="section">
    <h2>Contact Details</h2>
    <div class="field">
      <span class="label">Name</span>
      <span class="value">{$name}</span>
    </div>
    <div class="field">
      <span class="label">Email</span>
      <span class="value"><a href="mailto:{$email}" style="color:#c9a84c;">{$email}</a></span>
    </div>
    <div class="field">
      <span class="label">Phone</span>
      <span class="value">{$phone}</span>
    </div>
  </div>

  <div class="section">
    <h2>Lesson Details</h2>
    <div class="field">
      <span class="label">Program</span>
      <span class="value">{$lessonLabel}</span>
    </div>
    <div class="field">
      <span class="label">Experience Level</span>
      <span class="value">{$experienceLabel}</span>
    </div>
    <div class="field">
      <span class="label">Preferred Time</span>
      <span class="value">{$preferredTime}</span>
    </div>
  </div>

  <div class="section">
    <h2>Message</h2>
    <div class="message-box">{$message}</div>
  </div>

  <div class="footer">
    <p>This inquiry was submitted via garycolemanguitar.com on {$_SERVER['HTTP_HOST']}.</p>
    <p>Reply directly to <a href="mailto:{$email}" style="color:#c9a84c;">{$email}</a> to respond.</p>
  </div>
</div>
</body>
</html>
HTML;

// ── Send ──────────────────────────────────────────────────────
$boundary = md5(uniqid((string) rand(), true));

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
$headers .= "From: Gary Coleman Guitar <noreply@{$senderDomain}>\r\n";
$headers .= "Reply-To: {$name} <{$email}>\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "X-Priority: 3\r\n";

$body  = "--{$boundary}\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $plainBody . "\r\n\r\n";
$body .= "--{$boundary}\r\n";
$body .= "Content-Type: text/html; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $htmlBody . "\r\n\r\n";
$body .= "--{$boundary}--";

$sent = mail($recipientEmail, $subject, $body, $headers);

// ── Respond ───────────────────────────────────────────────────
if ($sent) {
    $_SESSION['submit_count']++;
    $_SESSION['last_submit'] = $now;

    echo json_encode([
        'success' => true,
        'message' => "Thank you, {$name}! Your message has been received. I'll be in touch within 24 hours."
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send your message. Please email me directly at gary@garycolemanguitar.com'
    ]);
}
