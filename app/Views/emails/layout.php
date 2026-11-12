<?php
/**
 * Email shell (the frame of the old resources/views/emails/order.blade.php): ivory page, white 600px card, logo,
 * gold rule, the template's body rows, optional footer note. Inline styles only (email clients ignore stylesheets).
 *
 * Templates in app/Views/emails/<name>.php are rendered by App\Mail\Mailer::render(): they echo TABLE ROWS
 * (<tr><td style="padding:...">...</td></tr>) and set $subject (and optionally $text, $footer).
 * Available: $brand ("ABIHA®"), $logo (absolute URL), $contactEmail, $contactPhone, $body, $subject.
 */
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($brand ?? 'ABIHA®') ?></title></head>
<body style="margin:0;padding:0;background:#F8F6F1;font-family:Arial,Helvetica,sans-serif;color:#101010;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F8F6F1;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#FFFFFF;border:1px solid #EAEAEA;">
    <tr><td align="center" style="padding:24px 24px 8px;"><img src="<?= e($logo ?? '') ?>" alt="<?= e($brand ?? 'ABIHA®') ?>" width="120" style="display:block;border:0;max-width:120px;height:auto;"></td></tr>
    <tr><td style="height:3px;background:#C99A35;font-size:0;line-height:0;">&nbsp;</td></tr>
    <?= $body ?? '' ?>
    <?php if (!empty($footer)): ?>
    <tr><td style="padding:16px 28px 24px;border-top:1px solid #EAEAEA;font-size:12px;line-height:1.5;color:#777777;"><?= $footer ?></td></tr>
    <?php endif; ?>
  </table>
</td></tr>
</table>
</body>
</html>
