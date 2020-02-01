--
--	Extension:Moderation - MediaWiki extension.
--	Copyright (C) 2014-2020 Edward Chernenko.
--
--	This program is free software; you can redistribute it and/or modify
--	it under the terms of the GNU General Public License as published by
--	the Free Software Foundation; either version 3 of the License, or
--	(at your option) any later version.
--
--	This program is distributed in the hope that it will be useful,
--	but WITHOUT ANY WARRANTY; without even the implied warranty of
--	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
--	GNU General Public License for more details.
--

-- Creates "moderation" table (PostgreSQL version).
-- NOTE: see [sql/patch-moderation.sql] (MySQL version) for documentation.

DROP SEQUENCE IF EXISTS moderation_mod_id_seq CASCADE;

CREATE SEQUENCE moderation_mod_id_seq;
CREATE TABLE moderation (
	mod_id INTEGER PRIMARY KEY DEFAULT nextval('moderation_mod_id_seq'),
	mod_timestamp TIMESTAMPTZ NOT NULL,
	mod_user INTEGER NOT NULL DEFAULT 0,
	mod_user_text TEXT NOT NULL,
	mod_cur_id INTEGER NOT NULL, -- page to be edited
	mod_namespace SMALLINT NOT NULL DEFAULT 0,
	mod_title TEXT NOT NULL DEFAULT '',
	mod_comment TEXT NOT NULL DEFAULT '',
	mod_minor SMALLINT NOT NULL DEFAULT 0,
	mod_bot SMALLINT NOT NULL DEFAULT 0,
	mod_new SMALLINT NOT NULL DEFAULT 0,
	mod_last_oldid INTEGER NOT NULL DEFAULT 0,
	mod_ip TEXT NOT NULL DEFAULT '',
	mod_old_len INTEGER,
	mod_new_len INTEGER,
	mod_header_xff TEXT,
	mod_header_ua TEXT,
	mod_tags TEXT,
	mod_preload_id TEXT NOT NULL,
	mod_rejected SMALLINT NOT NULL DEFAULT 0,
	mod_rejected_by_user INTEGER NOT NULL DEFAULT 0,
	mod_rejected_by_user_text TEXT DEFAULT NULL,
	mod_rejected_batch SMALLINT NOT NULL DEFAULT 0,
	mod_rejected_auto SMALLINT NOT NULL DEFAULT 0,
	mod_preloadable INTEGER NOT NULL DEFAULT 0,
	mod_conflict SMALLINT NOT NULL DEFAULT 0,
	mod_merged_revid INTEGER NOT NULL DEFAULT 0,
	mod_text TEXT,
	mod_stash_key TEXT DEFAULT NULL,
	mod_type TEXT NOT NULL DEFAULT 'edit',
	mod_page2_namespace SMALLINT NOT NULL DEFAULT 0,
	mod_page2_title TEXT NOT NULL DEFAULT ''
) /*$wgDBTableOptions*/;
ALTER SEQUENCE moderation_mod_id_seq OWNED BY moderation.mod_id;

CREATE UNIQUE INDEX /*i*/moderation_load ON moderation (mod_preloadable, mod_type, mod_namespace, mod_title, mod_preload_id);
CREATE INDEX /*i*/moderation_approveall ON moderation (mod_user_text, mod_rejected, mod_conflict);
CREATE INDEX /*i*/moderation_rejectall ON moderation (mod_user_text, mod_rejected, mod_merged_revid);
CREATE INDEX /*i*/moderation_folder_pending ON moderation (mod_rejected, mod_merged_revid, mod_timestamp);
CREATE INDEX /*i*/moderation_folder_rejected ON moderation (mod_rejected, mod_rejected_auto, mod_merged_revid, mod_timestamp);
CREATE INDEX /*i*/moderation_folder_merged ON moderation (mod_merged_revid, mod_timestamp);
CREATE INDEX /*i*/moderation_folder_spam ON moderation (mod_rejected_auto, mod_timestamp);
CREATE INDEX /*i*/moderation_signup ON moderation (mod_preload_id, mod_preloadable);
