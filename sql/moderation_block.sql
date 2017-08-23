--
--	Extension:Moderation - MediaWiki extension.
--	Copyright (C) 2014 Edward Chernenko.
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

--
--	"moderation_block" table lists users which should have their edits
--	automatically rejected (sent to the Spam folder).
--
--	This table is very similar to the ipblocks table.
--
--	NOTE: moderation blocks do not expire.
--	NOTE: range blocks are not supported.
--
CREATE TABLE /*_*/moderation_block (
	mb_id int unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,

	-- Fields with the same meaning as in ipblocks table
	mb_address tinyblob NOT NULL,
	mb_user int unsigned NOT NULL default 0,
	mb_by int unsigned NOT NULL default 0,
	mb_by_text varchar(255) binary NOT NULL default '',
	mb_timestamp binary(14) NOT NULL default ''
) /*$wgDBTableOptions*/;
CREATE UNIQUE INDEX /*i*/moderation_block_address ON /*_*/moderation_block (mb_address(255));
