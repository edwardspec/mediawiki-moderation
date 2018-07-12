#!/bin/bash
###############################################################################
# Assemble the directory with MediaWiki
# Usage: ./build_mediawiki REL1_31
###############################################################################

branch=$1
GITCLONE_OPTS="--depth 1 --recurse-submodules -j 5 -b $branch"

mkdir -p buildcache/mediawiki

if [ ! -f buildcache/mediawiki/COMPLETE ]; then
	(
		cd buildcache
		rm -rf mediawiki
		git clone $GITCLONE_OPTS https://gerrit.wikimedia.org/r/p/mediawiki/core.git mediawiki

		for EXT in AbuseFilter CheckUser MobileFrontend PageForms VisualEditor; do
			git clone $GITCLONE_OPTS \
				https://gerrit.wikimedia.org/r/p/mediawiki/extensions/$EXT.git \
				mediawiki/extensions/$EXT
		done

		for SKIN in MinervaNeue; do
			git clone $GITCLONE_OPTS \
				https://gerrit.wikimedia.org/r/p/mediawiki/skins/$SKIN.git \
				mediawiki/skins/$SKIN
		done

		cd mediawiki
		[[ -f includes/DevelopmentSettings.php ]] || \
			wget https://raw.githubusercontent.com/wikimedia/mediawiki/master/includes/DevelopmentSettings.php \
				-O includes/DevelopmentSettings.php

		find . -name .git | xargs rm -rf

		composer install --prefer-dist --quiet --no-interaction
		touch COMPLETE # Mark this buildcache as usable
	)
fi

cp -r buildcache/mediawiki ./
