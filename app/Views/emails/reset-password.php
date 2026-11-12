<?php
/**
 * Password reset email (the texts of Laravel's default ResetPassword notification, which the old site sent).
 *
 * @var string $url    the /reset-password/{token}?email=... link
 * @var int    $expire minutes the link stays valid
 */
$subject = 'Reset Password Notification';
$footer = 'If you\'re having trouble clicking the "Reset Password" button, copy and paste the URL below into your web browser: <a href="' . e($url) . '" style="color:#777777;word-break:break-all;">' . e($url) . '</a>';
$text = "Hello!\n\nYou are receiving this email because we received a password reset request for your account.\n\nReset Password: {$url}\n\n"
    . "This password reset link will expire in {$expire} minutes.\n\nIf you did not request a password reset, no further action is required.\n\nRegards,\n{$brand}";
?>
<tr><td style="padding:28px 28px 8px;font-size:22px;font-weight:bold;">Hello!</td></tr>
<tr><td style="padding:8px 28px;font-size:15px;line-height:1.6;">You are receiving this email because we received a password reset request for your account.</td></tr>
<tr><td align="center" style="padding:16px 28px;"><a href="<?= e($url) ?>" style="display:inline-block;background:#101010;color:#FFFFFF;text-decoration:none;font-weight:bold;padding:12px 28px;border-radius:4px;">Reset Password</a></td></tr>
<tr><td style="padding:8px 28px;font-size:15px;line-height:1.6;">This password reset link will expire in <?= (int) $expire ?> minutes.</td></tr>
<tr><td style="padding:8px 28px;font-size:15px;line-height:1.6;">If you did not request a password reset, no further action is required.</td></tr>
<tr><td style="padding:8px 28px 24px;font-size:15px;line-height:1.6;">Regards,<br><?= e($brand) ?></td></tr>
