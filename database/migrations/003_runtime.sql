-- Runtime tables of the plain-PHP version (new, additive; the Laravel tables are untouched).
--   rate_limits   fixed-window counters for App\Core\RateLimiter (replaces Laravel's cache-based throttle)
--   email_outbox  queued emails, delivered by App\Mail\Mailer::flush() after commit and by `php bin/console.php cron`
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket` varchar(64) NOT NULL,
  `window_start` int(10) unsigned NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket`,`window_start`),
  KEY `rate_limits_window_start_index` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(255) NOT NULL,
  `template` varchar(80) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `html` mediumtext NOT NULL,
  `text_body` mediumtext NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `send_after` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `email_outbox_status_send_after_index` (`status`,`send_after`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
