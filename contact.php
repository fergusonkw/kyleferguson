<?php
declare(strict_types=1);

// ── CORS ──────────────────────────────────────────────────────────────────────
// Restrict to same origin in production; widen only if your frontend is served
// from a different domain (e.g. Vercel → PHP hosting split).
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''), '/');
if ($origin === $allowed || $origin === '') {
    header('Access-Control-Allow-Origin: ' . ($origin ?: $allowed));
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Autoloader ────────────────────────────────────────────────────────────────
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server misconfiguration: run `composer install`.']);
    exit;
}
require $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\SMTP;

// ── Config ───────────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/mail-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server misconfiguration: mail-config.php missing.']);
    exit;
}
$cfg = require $configFile;

// ── Parse input ───────────────────────────────────────────────────────────────
$raw = json_decode(file_get_contents('php://input'), true);
if (!is_array($raw)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request body.']);
    exit;
}

function field(array $data, string $key): string {
    return trim((string)($data[$key] ?? ''));
}

$honeypot   = field($raw, '_hp');         // must be empty
$loadedAt   = (int)($raw['_t'] ?? 0);    // unix timestamp when form loaded
$name       = field($raw, 'name');
$email      = field($raw, 'email');
$company    = field($raw, 'company');
$type       = field($raw, 'type');
$message    = field($raw, 'message');
$copyToSelf = !empty($raw['copyToSelf']);

// ── Spam checks ───────────────────────────────────────────────────────────────

// 1. Honeypot — bots fill it, humans never see it
if ($honeypot !== '') {
    // Return a convincing success to prevent enumeration
    echo json_encode(['ok' => true, 'ticket' => 'KF-' . strtoupper(substr(md5((string)time()), 0, 6))]);
    exit;
}

// 2. Time trap — legitimate users take at least a few seconds to fill a form
$elapsed = time() - $loadedAt;
if ($loadedAt === 0 || $elapsed < 3) {
    echo json_encode(['ok' => true, 'ticket' => 'KF-' . strtoupper(substr(md5((string)time()), 0, 6))]);
    exit;
}

// 3. Basic rate limiting — 5 submissions per IP per hour via a flat file lock.
//    For high-traffic sites replace with Redis / DB.
$rateLimitDir  = sys_get_temp_dir() . '/kf_contact_rl';
$rateLimitFile = $rateLimitDir . '/' . md5($_SERVER['REMOTE_ADDR'] ?? 'anon') . '.json';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0700, true);
}
$rl = [];
if (file_exists($rateLimitFile)) {
    $rl = json_decode(file_get_contents($rateLimitFile), true) ?? [];
}
$window = 3600; // 1 hour
$maxHits = 5;
$now     = time();
$rl      = array_filter($rl, fn($ts) => ($now - $ts) < $window);
if (count($rl) >= $maxHits) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many submissions. Please try again later.']);
    exit;
}
$rl[] = $now;
file_put_contents($rateLimitFile, json_encode(array_values($rl)), LOCK_EX);

// ── Field validation ──────────────────────────────────────────────────────────
$errors = [];
if ($name === '')    $errors['name']    = 'Required';
if ($email === '')   $errors['email']   = 'Required';
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email';
if ($message === '') $errors['message'] = 'Tell me a little about it';

if ($errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

// ── Ticket number ─────────────────────────────────────────────────────────────
$ticket = 'KF-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
$date   = date('D, j M Y \a\t g:ia T');

// ── Email helper ──────────────────────────────────────────────────────────────
function buildMailer(array $cfg): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $cfg['smtp_host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['smtp_user'];
    $mail->Password   = $cfg['smtp_pass'];
    $mail->SMTPSecure = $cfg['smtp_secure'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)$cfg['smtp_port'];
    $mail->CharSet    = 'UTF-8';
    if (!empty($cfg['debug'])) {
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    }
    $mail->setFrom($cfg['from_email'], $cfg['from_name']);
    return $mail;
}

// ── Admin notification email template ────────────────────────────────────────
function adminEmailHtml(
    string $ticket, string $date,
    string $name, string $email, string $company,
    string $type, string $message, bool $copyToSelf
): string {
    $eName    = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
    $eEmail   = htmlspecialchars($email,   ENT_QUOTES, 'UTF-8');
    $eCompany = htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?: '<span style="color:#4b4e57">—</span>';
    $eType    = htmlspecialchars($type,    ENT_QUOTES, 'UTF-8') ?: '<span style="color:#4b4e57">—</span>';
    $eMsg     = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $copyNote = $copyToSelf ? 'Yes — sender CC'd' : 'No';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>New Inquiry — {$ticket}</title>
</head>
<body style="margin:0;padding:0;background:#0f1013;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;color:#e9e9ec;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f1013;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

      <!-- Header -->
      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;padding:28px 36px 24px;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td>
                <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;">KYLE FERGUSON</span>
              </td>
              <td align="right">
                <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.06em;color:#c0392b;">NEW INQUIRY</span>
              </td>
            </tr>
          </table>
          <div style="border-top:1px solid #2b2e36;margin:16px 0;"></div>
          <h1 style="margin:0;font-size:22px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;">
            {$eName} sent a message
          </h1>
          <p style="margin:8px 0 0;font-size:13px;color:#71747e;">{$date}</p>
        </td>
      </tr>

      <!-- Ticket bar -->
      <tr>
        <td style="background:#c0392b;padding:10px 36px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#fff;">TICKET {$ticket} · OPEN</span>
        </td>
      </tr>

      <!-- Fields -->
      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:30px 36px;">

          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td style="padding-bottom:20px;border-bottom:1px solid #2b2e36;">
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:5px;">NAME</span>
                <span style="font-size:15px;color:#e9e9ec;">{$eName}</span>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 0;border-bottom:1px solid #2b2e36;">
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:5px;">EMAIL</span>
                <a href="mailto:{$eEmail}" style="font-size:15px;color:#c0392b;text-decoration:none;">{$eEmail}</a>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 0;border-bottom:1px solid #2b2e36;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:5px;">COMPANY</span>
                      <span style="font-size:15px;color:#e9e9ec;">{$eCompany}</span>
                    </td>
                    <td width="4%"></td>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:5px;">PROJECT TYPE</span>
                      <span style="font-size:15px;color:#e9e9ec;">{$eType}</span>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 0 0;">
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:12px;">MESSAGE</span>
                <div style="font-size:15px;color:#e9e9ec;line-height:1.65;background:#15161a;border:1px solid #2b2e36;padding:16px 18px;">
                  {$eMsg}
                </div>
              </td>
            </tr>
          </table>

        </td>
      </tr>

      <!-- Footer meta -->
      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;border-top:none;padding:16px 36px;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td>
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.08em;color:#4b4e57;">COPY TO SENDER: {$copyNote}</span>
              </td>
              <td align="right">
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.08em;color:#4b4e57;">{$ticket}</span>
              </td>
            </tr>
          </table>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

// ── Sender confirmation email template ───────────────────────────────────────
function senderEmailHtml(
    string $ticket, string $date,
    string $name, string $email, string $company,
    string $type, string $message
): string {
    $firstName = htmlspecialchars(explode(' ', trim($name))[0], ENT_QUOTES, 'UTF-8');
    $eName     = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
    $eEmail    = htmlspecialchars($email,   ENT_QUOTES, 'UTF-8');
    $eCompany  = htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?: '<span style="color:#4b4e57">—</span>';
    $eType     = htmlspecialchars($type,    ENT_QUOTES, 'UTF-8') ?: '<span style="color:#4b4e57">—</span>';
    $eMsg      = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Message received — {$ticket}</title>
</head>
<body style="margin:0;padding:0;background:#0f1013;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;color:#e9e9ec;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f1013;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

      <!-- Header -->
      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;padding:32px 36px 28px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;">KYLE FERGUSON</span>
          <div style="border-top:1px solid #2b2e36;margin:16px 0;"></div>
          <h1 style="margin:0 0 10px;font-size:24px;font-weight:600;letter-spacing:-0.02em;color:#e9e9ec;line-height:1.2;">
            Message received,<br>{$firstName}.
          </h1>
          <p style="margin:0;font-size:15px;color:#71747e;line-height:1.6;max-width:420px;">
            I've logged your inquiry and will be in touch at
            <a href="mailto:{$eEmail}" style="color:#e9e9ec;text-decoration:none;">{$eEmail}</a>
            — usually within a couple of business days.
          </p>
        </td>
      </tr>

      <!-- Ticket bar -->
      <tr>
        <td style="background:#c0392b;padding:10px 36px;">
          <span style="font-family:monospace,monospace;font-size:11px;letter-spacing:0.1em;color:#fff;">TICKET {$ticket} · LOGGED</span>
        </td>
      </tr>

      <!-- Submission summary -->
      <tr>
        <td style="background:#1a1c21;border:1px solid #2b2e36;border-top:none;padding:28px 36px 24px;">
          <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:20px;">YOUR SUBMISSION</span>

          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td style="padding-bottom:16px;border-bottom:1px solid #2b2e36;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:4px;">NAME</span>
                      <span style="font-size:14px;color:#b6b8bf;">{$eName}</span>
                    </td>
                    <td width="4%"></td>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:4px;">EMAIL</span>
                      <span style="font-size:14px;color:#b6b8bf;">{$eEmail}</span>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:16px 0;border-bottom:1px solid #2b2e36;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:4px;">COMPANY</span>
                      <span style="font-size:14px;color:#b6b8bf;">{$eCompany}</span>
                    </td>
                    <td width="4%"></td>
                    <td width="48%">
                      <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:4px;">PROJECT TYPE</span>
                      <span style="font-size:14px;color:#b6b8bf;">{$eType}</span>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding-top:16px;">
                <span style="font-family:monospace,monospace;font-size:10px;letter-spacing:0.1em;color:#4b4e57;text-transform:uppercase;display:block;margin-bottom:10px;">MESSAGE</span>
                <div style="font-size:14px;color:#b6b8bf;line-height:1.65;background:#15161a;border:1px solid #2b2e36;padding:14px 16px;">
                  {$eMsg}
                </div>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <!-- CTA / info -->
      <tr>
        <td style="background:#15161a;border:1px solid #2b2e36;border-top:none;padding:24px 36px;">
          <p style="margin:0 0 6px;font-size:13px;color:#71747e;">
            Need to add anything? Reply to this email and it'll reach me directly.
          </p>
          <p style="margin:0;font-size:13px;color:#4b4e57;">
            &mdash; Kyle
          </p>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="padding:20px 36px 0;">
          <p style="margin:0;font-family:monospace,monospace;font-size:10px;letter-spacing:0.06em;color:#2b2e36;text-align:center;">
            kyleferguson.ca &nbsp;·&nbsp; {$date} &nbsp;·&nbsp; {$ticket}
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

// ── Plain-text fallbacks ──────────────────────────────────────────────────────
function adminEmailText(string $ticket, string $date, string $name, string $email, string $company, string $type, string $message, bool $copyToSelf): string {
    $copyNote = $copyToSelf ? 'Yes' : 'No';
    return "NEW INQUIRY — {$ticket}\n{$date}\n\n"
        . "NAME:         {$name}\n"
        . "EMAIL:        {$email}\n"
        . "COMPANY:      " . ($company ?: '—') . "\n"
        . "PROJECT TYPE: " . ($type ?: '—') . "\n"
        . "COPY TO SELF: {$copyNote}\n\n"
        . "MESSAGE:\n{$message}\n";
}

function senderEmailText(string $ticket, string $name, string $email, string $message): string {
    $firstName = explode(' ', trim($name))[0];
    return "Message received, {$firstName}.\n\n"
        . "I've logged your inquiry and will be in touch at {$email} — usually within a couple of business days.\n\n"
        . "Ticket: {$ticket}\n\n"
        . "— Kyle\n"
        . "kyleferguson.ca\n";
}

// ── Send emails ───────────────────────────────────────────────────────────────
try {
    // 1. Admin notification
    $mail = buildMailer($cfg);
    $mail->addAddress($cfg['to_email'], $cfg['to_name']);
    $mail->addReplyTo($email, $name);
    $mail->Subject  = "New inquiry [{$ticket}] — {$name}";
    $mail->isHTML(true);
    $mail->Body     = adminEmailHtml($ticket, $date, $name, $email, $company, $type, $message, $copyToSelf);
    $mail->AltBody  = adminEmailText($ticket, $date, $name, $email, $company, $type, $message, $copyToSelf);
    $mail->send();

    // 2. Sender copy (opt-in)
    if ($copyToSelf) {
        $copy = buildMailer($cfg);
        $copy->addAddress($email, $name);
        $copy->addReplyTo($cfg['from_email'], $cfg['from_name']);
        $copy->Subject  = "Your message to Kyle Ferguson — {$ticket}";
        $copy->isHTML(true);
        $copy->Body     = senderEmailHtml($ticket, $date, $name, $email, $company, $type, $message);
        $copy->AltBody  = senderEmailText($ticket, $name, $email, $message);
        $copy->send();
    }

    echo json_encode(['ok' => true, 'ticket' => $ticket]);

} catch (MailerException $e) {
    error_log('contact.php mailer error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail could not be sent. Please try again or email hello@kyleferguson.ca directly.']);
} catch (Throwable $e) {
    error_log('contact.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong. Please try again.']);
}
