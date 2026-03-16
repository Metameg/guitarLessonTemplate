<?php
/**
 * Student notification helper.
 *
 * Provides:
 *   getStudentForSlot(string $slotKey): ?array
 *   clearPendingBooking(string $slotKey): void
 *   notifyStudent(string $slotKey, string $newState): bool
 *
 * getStudentForSlot / clearPendingBooking can be called anywhere.
 * notifyStudent requires mailer-config.php to be loaded first (SMTP constants).
 * vendor/autoload.php must already be loaded before including this file.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ── Pending bookings helpers ──────────────────────────────────

function getStudentForSlot(string $slotKey): ?array {
    $file = __DIR__ . '/pending-bookings.json';
    if (!file_exists($file)) return null;
    $raw  = file_get_contents($file);
    $data = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?? []) : [];
    return isset($data[$slotKey]) && is_array($data[$slotKey]) ? $data[$slotKey] : null;
}

function clearPendingBooking(string $slotKey): void {
    $file = __DIR__ . '/pending-bookings.json';
    if (!file_exists($file)) return;
    $raw  = file_get_contents($file);
    $data = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?? []) : [];
    if (!array_key_exists($slotKey, $data)) return;
    unset($data[$slotKey]);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// ── Human-readable slot label ─────────────────────────────────

function fmtSlotLabel(string $slotKey, string $fallback = ''): string {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{1,2})$/', $slotKey, $m)) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $m[1]);
        if ($dt) {
            $h = (int)$m[2];
            $t = $h === 12 ? '12 PM' : ($h < 12 ? "{$h} AM" : ($h - 12) . ' PM');
            return $dt->format('l, F j, Y') . " at {$t}";
        }
    }
    return $fallback ?: $slotKey;
}

// ── Send student notification email ──────────────────────────

function notifyStudent(string $slotKey, string $newState): bool {
    // Requires mailer-config.php to be loaded
    if (!defined('SMTP_HOST')) {
        error_log('notifyStudent: mailer-config.php not loaded — email skipped');
        return false;
    }

    $student = getStudentForSlot($slotKey);
    if ($student === null) return false;

    $name      = $student['name']           ?? 'Student';
    $email     = $student['email']          ?? '';
    $slotLabel = fmtSlotLabel($slotKey, $student['preferred_time'] ?? $slotKey);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    // Only these three states trigger a student email
    if (!in_array($newState, ['booked', 'available', 'unavailable'], true)) return false;

    [$subject, $heading, $bodyHtml] = match($newState) {
        'booked' => [
            'Your Guitar Lesson is Confirmed — JCR Music Studio',
            'Lesson Confirmed',
            "Great news! Your lesson on <strong>{$slotLabel}</strong> has been confirmed."
            . "<br><br>We look forward to seeing you soon. If you have any questions before your first lesson, don't hesitate to reach out.",
        ],
        'available' => [
            'Regarding Your Guitar Lesson Request — JCR Music Studio',
            'Update on Your Request',
            "Thank you for reaching out to JCR Music Studio."
            . "<br><br>Unfortunately, we're unable to accommodate your request for <strong>{$slotLabel}</strong> at this time."
            . "<br><br>Please feel free to contact us and we'll find a time that works better for you.",
        ],
        'unavailable' => [
            'Your Guitar Lesson Has Been Cancelled — JCR Music Studio',
            'Lesson Cancelled',
            "We're reaching out to let you know that your lesson on <strong>{$slotLabel}</strong> has been cancelled."
            . "<br><br>We sincerely apologize for the inconvenience. Please reach out so we can get you rescheduled as soon as possible.",
        ],
    };

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #f0f2f5; font-family: Georgia, serif; padding: 32px 16px; }
    .wrap { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.10); }
    .hdr  { background: #06091a; padding: 28px 32px; }
    .hdr .studio { color: #60a5fa; font-size: 12px; letter-spacing: 2px; text-transform: uppercase; }
    .hdr h1 { color: #eef2ff; font-size: 20px; margin-top: 8px; font-weight: 700; }
    .body { padding: 32px; color: #333; font-size: 15px; line-height: 1.8; }
    .body p { margin-bottom: 14px; }
    strong { color: #1d4ed8; }
    .sig { margin-top: 24px; font-size: 14px; color: #555; border-top: 1px solid #eee; padding-top: 20px; }
    .sig a { color: #1d4ed8; text-decoration: none; }
    .ftr { background: #f8f9fb; border-top: 1px solid #e8eaf0; padding: 14px 32px; font-size: 11px; color: #999; text-align: center; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="hdr">
      <div class="studio">JCR Music Studio</div>
      <h1>{$heading}</h1>
    </div>
    <div class="body">
      <p>Hi {$name},</p>
      <p>{$bodyHtml}</p>
      <div class="sig">
        — James C. Robbins<br>
        <em>JCR Music Studio &middot; Clear Lake, TX</em><br>
        <a href="mailto:aquarianmusic@yahoo.com">aquarianmusic@yahoo.com</a> &middot; (713)-569-0434
      </div>
    </div>
    <div class="ftr">JCR Music Studio &middot; Clear Lake, TX &middot; (713)-569-0434</div>
  </div>
</body>
</html>
HTML;

    $plainBody = "Hi {$name},\n\n"
        . strip_tags(str_replace(['<br><br>', '<br>'], ["\n\n", "\n"], $bodyHtml))
        . "\n\n— James C. Robbins\nJCR Music Studio · Clear Lake, TX\naquarianmusic@yahoo.com · (713)-569-0434";

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($email, $name);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log("notifyStudent mailer error [{$slotKey}]: " . $e->getMessage());
        return false;
    }
}
