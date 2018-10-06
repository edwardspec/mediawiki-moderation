<?php

/**
 * @file
 * Backward compatibility file to support require_once() in LocalSettings.
 *
 * Modern syntax (to enable Moderation in LocalSettings.php) is
 * wfLoadExtension( 'Moderation' );
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'Moderation' );
} else {
	die( 'This version of the Moderation extension requires MediaWiki 1.27+' );
}
