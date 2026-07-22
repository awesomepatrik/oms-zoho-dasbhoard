<?php
/**
 * Outbound mail via SMTP (PHPMailer). Used for password reset/account-setup links.
 *
 * Config keys (see zoho-dashboard-config/config.example.php):
 *   smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption ('tls'|'ssl'),
 *   mail_from, mail_from_name
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function mailer_send(string $toEmail, string $toName, string $subject, string $bodyText): bool
{
    $cfg = get_config();

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'] ?? '';
        $mail->Port       = (int)($cfg['smtp_port'] ?? 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_username'] ?? '';
        $mail->Password   = $cfg['smtp_password'] ?? '';
        $mail->SMTPSecure = ($cfg['smtp_encryption'] ?? 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($cfg['mail_from'] ?? 'no-reply@localhost', $cfg['mail_from_name'] ?? 'Dashboard');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $bodyText;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log("mailer_send: failed to send to {$toEmail} — subject: {$subject} — " . $mail->ErrorInfo);
        return false;
    }
}
