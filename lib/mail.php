<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

function send_email(string $toEmail, string $toName, string $subject, string $html, string $text = ''): bool {
    // If SMTP is configured, use PHPMailer via SMTP
    if (defined('SMTP_HOST') && SMTP_HOST) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->Port       = SMTP_PORT ?: 587;
            if (!empty(SMTP_SECURE)) $mail->SMTPSecure = SMTP_SECURE;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;

            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->addAddress($toEmail, $toName ?: $toEmail);
            if (MAIL_BCC) $mail->addBCC(MAIL_BCC);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text ?: strip_tags(str_replace(["<br>","<br/>","<br />"], "\n", $html));

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('Email send failed: '.$e->getMessage());
            return false;
        }
    }

    // Fallback to PHP mail() if no SMTP configured
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: ".MAIL_FROM_NAME." <".MAIL_FROM.">\r\n";
    if (MAIL_BCC) $headers .= "Bcc: ".MAIL_BCC."\r\n";
    return @mail($toEmail, $subject, $html, $headers);
}
