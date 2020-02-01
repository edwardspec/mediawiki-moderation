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

-- Creates "moderation_block" table (PostgreSQL version).
-- NOTE: see [sql/patch-moderation_block.sql] (MySQL version) for documentation.

DROP SEQUENCE IF EXISTS moderation_block_mb_id_seq CASCADE;

CREATE SEQUENCE moderation_block_mb_id_seq;
CREATE TABLE /*_*/moderation_block (
	mb_id INTEGER PRIMARY KEY DEFAULT nextval('moderation_block_mb_id_seq'),
	mb_address TEXT NOT NULL,
	mb_user INTEGER NOT NULL DEFAULT 0,
	mb_by INTEGER NOT NULL DEFAULT 0,
	mb_by_text TEXT NOT NULL DEFAULT '',
	mb_timestamp TIMESTAMPTZ NOT NULL
) /*$wgDBTableOptions*/;
ALTER SEQUENCE moderation_block_mb_id_seq OWNED BY moderation_block.mb_id;

CREATE UNIQUE INDEX /*i*/moderation_block_address ON /*_*/moderation_block (mb_address);
