-- Optional phone picture for a banner (a tall, portrait version shown instead of the wide picture on phones, where the
-- hero fills the screen). Added only when the column is not there yet, so it is safe on any database.
SET @has_mobile_image := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'banners' AND COLUMN_NAME = 'mobile_image');
SET @add_mobile_image := IF(@has_mobile_image = 0, 'ALTER TABLE banners ADD COLUMN mobile_image VARCHAR(255) NULL AFTER image', 'SELECT 1');
PREPARE add_mobile_image_stmt FROM @add_mobile_image;
EXECUTE add_mobile_image_stmt;
DEALLOCATE PREPARE add_mobile_image_stmt;
