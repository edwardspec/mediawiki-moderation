--
--	Extension:Moderation - MediaWiki extension.
--	Copyright (C) 2014-2018 Edward Chernenko.
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
--	"moderation" table lists:
--	1) not yet approved changes,
--	2) rejected changes (for some period of time)
--
--	It does NOT list approved changes or logs.
--	"Who approved what" is in the general logging table.
--
--	This table is very similar to the recentchanges table.
--
--	NOTE: changes to the text are represented by the resulting text
--	(mod_text). If other edits were made to the page in question
--	(while this edit was awaiting moderation), a unified diff
--	will be generated and the newest revision will be patched.

CREATE TABLE /*_*/moderation (
	-- Part 1. Fields similar to the "recentchanges" table.

	mod_id int unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
	mod_timestamp varbinary(14) NOT NULL DEFAULT '',

	mod_user int unsigned NOT NULL default 0,
	mod_user_text varchar(255) binary NOT NULL,

	-- NOTE: existing page is referred to by mod_title and mod_namespace
	-- mod_cur_id is only saved in case the page was moved.

	mod_cur_id int unsigned NOT NULL, -- page to be edited
	mod_namespace int NOT NULL default 0,
	mod_title varchar(255) binary NOT NULL default '',

	mod_comment varchar(255) binary NOT NULL default '',
	mod_minor tinyint unsigned NOT NULL default 0,
	mod_bot tinyint unsigned NOT NULL default 0,
	mod_new tinyint unsigned NOT NULL default 0,

	-- mod_last_oldid - the revision ID (page_latest) at the moment
	-- when this edit was scheduled for moderation.
	--
	-- If, when the edit is approved, this revision is not latest,
	-- diff will be generated and latest revision will be patched.

	mod_last_oldid int unsigned NOT NULL default 0,

	mod_ip varbinary(40) NOT NULL default '', -- IP address

	mod_old_len int, -- Length of mod_last_oldid revision
	mod_new_len int, -- Length of this proposed (not moderated) revision

	--	Part 2. Fields needed for CheckUser extension etc.

	mod_header_xff varbinary(255) NULL default '', -- contents of 'X-Forwarded-For' request header
	mod_header_ua varbinary(255) NULL default '', -- contents of 'User-Agent' request header
	mod_tags blob NULL default '',  -- \n-separated list of ChangeTags (tags assigned by AbuseFilter, etc.)

	--	Part 3. Moderation-specific fields.

	-- mod_preload_id:
	-- Identifies both logged-in and anonymous users. Allows sequential
	-- edits, even if not logged in. See ModerationPreload.php for details.
	mod_preload_id varchar(256) binary NOT NULL,

	mod_rejected tinyint NOT NULL default 0, -- Set to 1 if rejected
	mod_rejected_by_user int unsigned NOT NULL default 0, -- Moderator's user ID
	mod_rejected_by_user_text varchar(255) binary DEFAULT NULL, -- Moderator's username

	mod_rejected_batch tinyint NOT NULL default 0, -- Set to 1 if "reject all edits by this user" button was used
	mod_rejected_auto tinyint NOT NULL default 0, -- Set to 1 if this user was marked with "reject all future edits from this user"

	-- Whether the user can continue changing this edit.
	-- mod_preloadable=0 means "Yes" (pending edits and edits with rejected_auto=1)
	-- mod_preloadable=mod_id means "No" (merged and rejected edits)
	--
	-- This field is used for making moderation_load index UNIQUE:
	-- user A can have only one pending edit in page B,
	-- user A can have many rejected edits in page B.
	mod_preloadable int unsigned NOT NULL default 0,

	mod_conflict tinyint NOT NULL default 0, -- Set to 1 if moderator tried to approve this, but "needs manual merging" error occured
	mod_merged_revid int unsigned NOT NULL default 0, -- If not 0, moderator has already merged this, and this is the revision number of the result.

	mod_text MEDIUMBLOB, -- Resulting text of proposed edit
	mod_stash_key varchar(255) DEFAULT NULL, -- If this edit is image upload, contains stash key. NULL for normal edits.

	-- Type of change, e.g. "edit" or "move".
	-- Note: uploads use mod_type=edit, because they modify the text of "File:Something" page.
	mod_type varchar(16) binary not null default 'edit',

	-- Additional page title (not applicable to mod_type=edit).
	-- When renaming the page (mod_type=move), these fields contain new pagename.
	mod_page2_namespace int NOT NULL default 0,
	mod_page2_title varchar(255) binary NOT NULL default ''

) /*$wgDBTableOptions*/;

--
--	"moderation_load" index is used by loadUnmoderatedEdit().
--
CREATE UNIQUE INDEX /*i*/moderation_load ON /*_*/moderation (mod_preloadable, mod_type, mod_namespace, mod_title, mod_preload_id);

--
--	"moderation_approveall" and "moderation_rejectall" are used by approveall/rejectall modactions.
--	The difference is that you can reject an edit with mod_conflict=1, but not approve it.
--	These indexes can't be merged into one by removing the last field,
--	because theoretically one user may have many merged edits, so we need them filtered by the index.
--
CREATE INDEX /*i*/moderation_approveall ON /*_*/moderation (mod_user_text, mod_rejected, mod_conflict);
CREATE INDEX /*i*/moderation_rejectall ON /*_*/moderation (mod_user_text, mod_rejected, mod_merged_revid);

--
--	"moderation_folder_FOLDERNAME" are indexes used on Special:Moderation to view folders.
--	Note: "mod_timestamp" always comes last, because it is used for sorting.
--
CREATE INDEX /*i*/moderation_folder_pending ON /*_*/moderation (mod_rejected, mod_merged_revid, mod_timestamp);
CREATE INDEX /*i*/moderation_folder_rejected ON /*_*/moderation (mod_rejected, mod_rejected_auto, mod_merged_revid, mod_timestamp);
CREATE INDEX /*i*/moderation_folder_merged ON /*_*/moderation (mod_merged_revid, mod_timestamp);
CREATE INDEX /*i*/moderation_folder_spam ON /*_*/moderation (mod_rejected_auto, mod_timestamp);

CREATE INDEX /*i*/moderation_signup ON /*_*/moderation (mod_preload_id, mod_preloadable);
