<?php

declare(strict_types=1);

/* The test email used to prove the mail settings (php bin/console.php mail:test you@example.com). */

use App\Core\Config;
use App\Mail\Mailer;

T::$current = 'Mail test';
Mailer::$sent = [];
Config::set('mail.from_address', 'no-reply@abiha.pk');
Mailer::sendTest('owner@example.test');
T::eq(1, count(Mailer::$sent), 'the test email is sent');
T::eq('owner@example.test', Mailer::$sent[0]['to'] ?? null, 'to the address given');
T::contains('test email', (string) (Mailer::$sent[0]['subject'] ?? ''), 'with a clear subject');
T::contains('no-reply@abiha.pk', (string) (Mailer::$sent[0]['html'] ?? ''), 'and names the sending address');
T::contains('Your email settings work', (string) (Mailer::$sent[0]['html'] ?? ''), 'in the shop email frame');
Mailer::$sent = [];
