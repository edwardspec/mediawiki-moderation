#!/bin/bash
###############################################################################
# Assemble the directory with MediaWiki
# Usage: ./build_mediawiki REL1_31
###############################################################################

branch=$1
GITCLONE_OPTS="--depth 1 --recurse-submodules -j 5"

# Clone extension/skin of the chosen REL_ branch (if exists).
# If REL_ branch doesn't exist (which happens when testing against trunk
# or pre-release of MediaWiki core), "master" branch is used instead.
#
function clonebranch()
{
	# Shorter Travis logs: hide everything except "git clone" command
	{ set +x; } 2>/dev/null

	URL=$1
	TARGET_DIRECTORY=$2
	REL_BRANCH=$branch

	# Skip if already cloned (e.g. if this extension is shipped with MediaWiki core)
	[[ -d $TARGET_DIRECTORY ]] && return 0

	# Check if REL_ branch is available. If not, use "master" as fallback.
	git ls-remote --heads --exit-code $URL $REL_BRANCH >/dev/null || {
		echo "NOTE: can't find $REL_BRANCH branch for $TARGET_DIRECTORY, using master branch instead." >&2
		REL_BRANCH=master
	}

	set -x

	# Download the sources
	git clone -b $REL_BRANCH $GITCLONE_OPTS $URL $TARGET_DIRECTORY
}

mkdir -p buildcache/mediawiki

if [ ! -f buildcache/mediawiki/COMPLETE ]; then
	(
		cd buildcache
		rm -rf mediawiki
		clonebranch https://gerrit.wikimedia.org/r/mediawiki/core.git mediawiki

		cd mediawiki

		( cd extensions
		for EXT in AbuseFilter CheckUser Echo MobileFrontend PageForms VisualEditor; do
			clonebranch https://gerrit.wikimedia.org/r/mediawiki/extensions/$EXT.git $EXT
		done
		)

		( cd skins
		for SKIN in Vector MinervaNeue; do
			clonebranch https://gerrit.wikimedia.org/r/mediawiki/skins/$SKIN.git $SKIN
		done
		)

		[[ -f includes/DevelopmentSettings.php ]] || \
			wget https://raw.githubusercontent.com/wikimedia/mediawiki/master/includes/DevelopmentSettings.php \
				-O includes/DevelopmentSettings.php

		find . -name .git | xargs rm -rf

		composer install --quiet --no-interaction
		touch COMPLETE # Mark this buildcache as usable
	)
fi

cp -r buildcache/mediawiki ./
