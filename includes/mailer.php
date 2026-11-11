<?php
// includes/mailer.php — PHPMailer via Composer (vendor/autoload.php at project root)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_mail(string $to, string $toName, string $subject, string $htmlBody): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to, $toName);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Mail error: ' . $e->getMessage() . ' | ' . ($mail->ErrorInfo ?? ''));
        return false;
    }
}

function email_template(string $title, string $bodyHtml): string {
    $site = SITE_NAME;
    return <<<HTML
    <div style="font-family:Segoe UI,Arial,sans-serif;background:#f4f6f9;padding:24px;">
      <div style="max-width:560px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">
        <div style="background:linear-gradient(135deg,#003366,#002244);padding:20px 24px;color:#fff;">
          <h2 style="margin:0;font-size:18px;">{$site}</h2>
          <p style="margin:4px 0 0;font-size:12px;opacity:.85;">University of Eastern Africa, Baraton</p>
        </div>
        <div style="padding:24px;">
          <h3 style="margin:0 0 12px;color:#003366;font-size:16px;">{$title}</h3>
          <div style="color:#333;font-size:14px;line-height:1.6;">{$bodyHtml}</div>
        </div>
        <div style="background:#f4f6f9;padding:14px 24px;color:#888;font-size:11px;text-align:center;">
          &copy; {$site} · UEAB Hostel Booking
        </div>
      </div>
    </div>
    HTML;
}

function send_magic_link_email(string $to, string $name, string $link): bool {
    $body = "
      <p>Hello <strong>" . h($name) . "</strong>,</p>
      <p>Your personalized hostel booking link is ready:</p>
      <p style='text-align:center;margin:24px 0;'>
        <a href='{$link}' style='background:#003366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
           Book My Room
        </a>
      </p>
      <p style='font-size:12px;color:#666;'>If the button doesn't work, paste this link:<br>
      <a href='{$link}' style='word-break:break-all;color:#003366;'>{$link}</a></p>
      <p style='font-size:12px;color:#999;'>This link expires in 7 days. Do not share it.</p>
    ";
    return send_mail($to, $name, "ComfortBook — Your Hostel Booking Link", email_template("Hostel Booking Link", $body));
}

function send_otp_email(string $to, string $name, string $otp): bool {
    $ttl = get_config('otp_ttl_minutes', '10');
    $body = "
      <p>Hello <strong>" . h($name) . "</strong>,</p>
      <p>Your one-time verification code is:</p>
      <p style='text-align:center;font-size:34px;font-weight:700;letter-spacing:8px;color:#003366;margin:24px 0;'>{$otp}</p>
      <p style='font-size:12px;color:#666;'>This code expires in {$ttl} minutes.</p>
    ";
    return send_mail($to, $name, "ComfortBook — Your OTP Code", email_template("Verify Your Booking", $body));
}

function send_welcome_email(string $to, string $name, string $roomNumber, string $blockName): bool {
    $body = "
      <p>Hello <strong>" . h($name) . "</strong>,</p>
      <p>Your room booking is <strong style='color:#198754;'>confirmed</strong>!</p>
      <div style='background:#f0f8f4;border-left:4px solid #198754;padding:14px 16px;border-radius:8px;margin:16px 0;'>
        <div style='font-size:12px;color:#666;text-transform:uppercase;'>Your Room</div>
        <div style='font-size:20px;font-weight:700;color:#003366;'>{$roomNumber}</div>
        <div style='font-size:13px;color:#666;'>{$blockName}</div>
      </div>
      <p>Welcome to the next semester!</p>
    ";
    return send_mail($to, $name, "ComfortBook — Booking Confirmed", email_template("Booking Confirmed", $body));
}

/* ---------------------------------------------------------------- booking emails (student AND hostel staff) */
function send_confirmation_email(string $to, string $name, string $roomNumber, string $blockName): bool {
    $body = "
      <p>Hello <strong>" . h($name) . "</strong>,</p>
      <p>Good news: your booking has been <strong style='color:#198754;'>approved</strong>.</p>
      <div style='background:#f0f8f4;border-left:4px solid #198754;padding:14px 16px;border-radius:8px;margin:16px 0;'>
        <div style='font-size:12px;color:#666;text-transform:uppercase;'>Your Room</div>
        <div style='font-size:20px;font-weight:700;color:#003366;'>" . h($roomNumber) . "</div>
        <div style='font-size:13px;color:#666;'>" . h($blockName) . "</div>
      </div>
      <p style='font-size:13px;color:#666;'>Your room is assigned when the semester starts. You will get another email then.</p>
    ";
    return send_mail($to, $name, "ComfortBook — Booking Approved", email_template("Booking Approved", $body));
}
