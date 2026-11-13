-- Website traffic analytics (first-party, no cookies, no raw IP addresses): one row per counted page view.
-- "visitor" is a one-way hash of IP + browser + day + the site key, so the same person is only recognised within one day.
CREATE TABLE IF NOT EXISTS page_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    visitor CHAR(24) NOT NULL,
    path VARCHAR(255) NOT NULL,
    channel VARCHAR(12) NOT NULL DEFAULT 'Direct',
    source VARCHAR(120) NULL,
    device VARCHAR(10) NOT NULL DEFAULT 'desktop',
    browser VARCHAR(24) NOT NULL DEFAULT 'Other',
    os VARCHAR(24) NOT NULL DEFAULT 'Other',
    PRIMARY KEY (id),
    KEY page_views_created_at (created_at),
    KEY page_views_path_created (path(120), created_at),
    KEY page_views_visitor_created (visitor, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
