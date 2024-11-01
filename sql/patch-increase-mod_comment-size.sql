-- Increase the size of mod_comment column
ALTER TABLE /*_*/moderation MODIFY COLUMN mod_comment TEXT NOT NULL;
