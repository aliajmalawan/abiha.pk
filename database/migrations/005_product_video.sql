-- Optional product video: either a YouTube / Vimeo / direct .mp4 or .webm link, or the path of an uploaded video file
-- (storage/app/public/videos/...). Added only when the column is not there yet, so it is safe on any database.
SET @has_video := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'video');
SET @add_video := IF(@has_video = 0, 'ALTER TABLE products ADD COLUMN video VARCHAR(255) NULL', 'SELECT 1');
PREPARE add_video_stmt FROM @add_video;
EXECUTE add_video_stmt;
DEALLOCATE PREPARE add_video_stmt;
