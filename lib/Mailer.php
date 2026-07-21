<?php
/**
 * Minimal outbound mail via PHP's mail(). Used for password reset links.
 * Swap this out for SMTP/PHPMailer later without touching callers of mailer_send().
 */

require_once __DIR__ . '/helpers.php';

function mailer_send(string $toEmail, string $toName, string $subject, string $bodyText): bool
{
    $cfg      = get_config();
    $fromAddr = $cfg['mail_from'] ?? 'no-reply@localhost';
    $fromName = $cfg['mail_from_name'] ?? 'Dashboard';

    $headers = implode("\r\n", [
        'From: ' . sprintf('%s <%s>', mb_encode_mimeheader($fromName), $fromAddr),
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ]);

    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');
    $to             = sprintf('%s <%s>', mb_encode_mimeheader($toName), $toEmail);

    $sent = @mail($to, $encodedSubject, $bodyText, $headers);
    if (!$sent) {
        error_log("mailer_send: failed to send to {$toEmail} — subject: {$subject}");
    }
    return $sent;
}
