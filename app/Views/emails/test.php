<?php
/** A test email, sent by `php bin/console.php mail:test you@example.com` to prove that the mail settings work. */
$subject = ($brand ?? 'ABIHA') . ': test email';
$text = "Hello!\n\nThis is a test email from your website. If you can read it, your mail settings are correct: order confirmations and password-reset emails will be delivered.\n\nRegards,\n" . ($brand ?? 'ABIHA');
?>
<tr><td style="padding:28px 28px 8px;font-size:22px;font-weight:bold;">Your email settings work</td></tr>
<tr><td style="padding:8px 28px 24px;font-size:15px;line-height:1.6;">This is a test email from your website. If you can read it, order confirmations and password-reset emails will be delivered from <strong><?= e((string) \App\Core\Config::get('mail.from_address')) ?></strong>.</td></tr>
