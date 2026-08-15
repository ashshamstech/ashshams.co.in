<?php
/**
 * Contact form handler.
 *
 * Takes a POST from the form in index.html, validates it, and mails it to
 * the address in config.php. Responds with JSON when the browser asks for
 * it (the AJAX path) and with a plain HTML page otherwise, so the form
 * still works with JavaScript disabled.
 */

declare(strict_types=1);

const MAX_LENGTHS = [
    'name'    => 100,
    'email'   => 254,   // RFC 5321 limit on a mailbox address
    'subject' => 150,
    'message' => 5000,
];

$config = require __DIR__ . '/config.php';

/**
 * Does the client want JSON back?
 */
function wants_json(): bool
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        return true;
    }
    return strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
}

/**
 * Send the response and stop, in whichever format the client asked for.
 */
function respond(bool $ok, string $message, int $status = 200): void
{
    global $config;

    http_response_code($status);

    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message]);
        exit;
    }

    $heading  = $ok ? 'Thank you' : 'Something went wrong';
    $safeMsg  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeBack = htmlspecialchars((string) $config['return_url'], ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{$heading} - AshShams Technologies</title>
        <style>
            body { font-family: 'Poppins', 'Helvetica Neue', Arial, sans-serif; color: #333;
                   display: flex; align-items: center; justify-content: center;
                   min-height: 100vh; margin: 0; padding: 24px; text-align: center; }
            .box { max-width: 480px; }
            h1 { font-size: 24px; margin: 0 0 12px; }
            p { color: #888; line-height: 1.6; margin: 0 0 24px; }
            a { display: inline-block; padding: 14px 28px; border-radius: 5px;
                background: #473BF0; color: #fff; text-decoration: none; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>{$heading}</h1>
            <p>{$safeMsg}</p>
            <a href="{$safeBack}">Back to the site</a>
        </div>
    </body>
    </html>
    HTML;
    exit;
}

/**
 * Collapse whitespace and strip anything that could start a new mail header.
 *
 * Values from the form end up in the Subject and Reply-To headers. A raw
 * CR or LF there would let a sender inject headers of their own (an extra
 * Bcc, for instance), so those characters never survive this function.
 */
function clean_header_value(string $value): string
{
    $value = str_replace(["\r", "\n", "\0", '%0a', '%0d', '%0A', '%0D'], ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

/**
 * Normalise a body field: strip null bytes, keep newlines, trim.
 */
function clean_body_value(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $value);
    return trim($value);
}

/**
 * Encode a header value as MIME if mbstring is available.
 *
 * Almost every host has the extension, but a missing one should degrade to
 * a plain header rather than a fatal error.
 */
function encode_header(string $value): string
{
    return function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($value, 'UTF-8')
        : $value;
}

/**
 * Build a "Display Name <address>" header value.
 *
 * A display name containing RFC 5322 "specials" (a colon or comma, say)
 * has to be quoted, or a strict parser can misread where the name ends.
 * Non-ASCII names are MIME encoded instead, which is already quoting-safe.
 */
function format_address(string $name, string $email): string
{
    if ($name === '') {
        return $email;
    }

    if (preg_match('/[^\x20-\x7E]/', $name)) {
        return sprintf('%s <%s>', encode_header($name), $email);
    }

    if (preg_match('/[()<>@,;:\\".\[\]]/', $name)) {
        $name = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
    }

    return sprintf('%s <%s>', $name, $email);
}

/**
 * Length of a string in characters, whether or not mbstring is present.
 */
function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/**
 * Best guess at the client IP, used only for rate limiting.
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Allow at most $limit submissions per IP inside $window seconds.
 *
 * State lives in the system temp directory, which is writable on shared
 * hosts without having to create a writable folder inside the web root.
 * If the counter cannot be read or written we let the request through
 * rather than block a genuine enquiry.
 */
function within_rate_limit(int $limit, int $window): bool
{
    if ($limit <= 0 || $window <= 0) {
        return true;
    }

    $file = sys_get_temp_dir() . '/ashshams-contact-' . sha1(client_ip()) . '.json';
    $now  = time();

    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return true;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return true;
        }

        $contents = stream_get_contents($handle);
        $stamps   = json_decode($contents ?: '[]', true);
        if (!is_array($stamps)) {
            $stamps = [];
        }

        // Drop anything that has aged out of the window.
        $stamps = array_values(array_filter(
            $stamps,
            static fn($t): bool => is_int($t) && $t > $now - $window
        ));

        if (count($stamps) >= $limit) {
            return false;
        }

        $stamps[] = $now;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($stamps));
        fflush($handle);

        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(false, 'This address only accepts form submissions.', 405);
}

// Honeypot: a field hidden from people, irresistible to bots. Report success
// so the bot has no signal that it was caught.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    respond(true, 'Thank you for getting in touch. We will reply shortly.');
}

// Time trap: the form stamps when it was rendered.
$minSeconds = (int) $config['min_seconds_on_page'];
if ($minSeconds > 0) {
    $renderedAt = (int) ($_POST['rendered_at'] ?? 0);
    if ($renderedAt > 0 && (time() - $renderedAt) < $minSeconds) {
        respond(false, 'That was submitted a little too quickly. Please try again.', 422);
    }
}

if (!within_rate_limit((int) $config['rate_limit'], (int) $config['rate_limit_window'])) {
    respond(false, 'Too many messages sent from this connection. Please try again later.', 429);
}

$name    = clean_header_value((string) ($_POST['name'] ?? ''));
$email   = clean_header_value((string) ($_POST['email'] ?? ''));
$subject = clean_header_value((string) ($_POST['subject'] ?? ''));
$message = clean_body_value((string) ($_POST['message'] ?? ''));

$errors = [];

if ($name === '') {
    $errors[] = 'your name';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'a valid email address';
}
if ($message === '') {
    $errors[] = 'a message';
}

if ($errors !== []) {
    respond(false, 'Please provide ' . implode(', ', $errors) . '.', 422);
}

$fields = ['name' => $name, 'email' => $email, 'subject' => $subject, 'message' => $message];
foreach (MAX_LENGTHS as $field => $limit) {
    if (text_length($fields[$field]) > $limit) {
        respond(false, ucfirst($field) . " must be {$limit} characters or fewer.", 422);
    }
}

if ($subject === '') {
    $subject = 'New enquiry from the website';
}

// ---------------------------------------------------------------------------

$fromEmail = (string) $config['from_email'];
$recipient = (string) $config['recipient'];

// A misconfigured address would otherwise surface as a confusing mail error.
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    error_log('contact.php: recipient or from_email in config.php is not a valid address');
    respond(false, 'The contact form is not configured correctly. Please email us directly.', 500);
}

$body = "You have a new enquiry from the AshShams Technologies website.\n\n"
    . "Name:    {$name}\n"
    . "Email:   {$email}\n"
    . "Subject: {$subject}\n"
    . 'Sent:    ' . date('Y-m-d H:i:s T') . "\n"
    . "\n-------------------------------------------\n\n"
    . $message
    . "\n";

$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    // From is our own domain so the mail passes SPF/DMARC; the visitor's
    // address goes in Reply-To so replying reaches them.
    'From: ' . format_address((string) $config['from_name'], $fromEmail),
    'Reply-To: ' . format_address($name, $email),
    'X-Mailer: PHP/' . phpversion(),
];

$encodedSubject = encode_header($config['subject_prefix'] . $subject);

// The -f parameter is built from our own configured address, never from
// anything the visitor submitted, so there is nothing to escape from.
$parameters = $config['set_envelope_sender'] ? '-f' . $fromEmail : '';

$sent = @mail($recipient, $encodedSubject, $body, implode("\r\n", $headers), $parameters);

if (!$sent) {
    error_log('contact.php: mail() failed for an enquiry from ' . $email);
    respond(
        false,
        'We could not send your message just now. Please email us directly at ' . $recipient . '.',
        500
    );
}

respond(true, 'Thank you for getting in touch. We will reply shortly.');
