<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2016-2019 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Adds ajaxhook-related JavaScript modules when they are needed.

	Default behavior: automatically check for presence of extension.
	For example, if Extension:VisualEditor is detected,
	then module 'ext.moderation.ve' will be attached.

	This can be overridden in LocalSettings.php:
	$wgModerationSupportVisualEditor = true; - attach even if not detected.
	$wgModerationSupportVisualEditor = false; - don't attach even if detected.
	$wgModerationSupportVisualEditor = "guess"; - default behavior.

	If at least one module is attached (or if $wgModerationForceAjaxHook is
	set to true), "ext.moderation.ajaxhook" will also be attached.
*/

class ModerationAjaxHook {

	/**
	 * Depending on $configName being true/false/"guess", return true/false/$default.
	 * @return bool
	 */
	protected static function need( $configName, $default ) {
		$config = RequestContext::getMain()->getConfig();
		$val = $config->get( $configName );
		return ( is_bool( $val ) ? $val : $default );
	}

	/** Convenience method: returns true if in Mobile skin, false otherwise */
	protected static function isMobile() {
		return ( class_exists( 'MobileContext' ) &&
			MobileContext::singleton()->shouldDisplayMobileView() );
	}

	/**
	 * Guess whether VisualEditor needs to be supported
	 * @return bool
	 */
	protected static function guessVE() {
		return ( class_exists( 'ApiVisualEditorEdit' ) && !self::isMobile() );
	}

	/**
	 * Add needed modules to $out.
	 */
	public static function add( OutputPage &$out ) {
		global $wgVersion;
		$modules = [];

		if ( self::need( 'ModerationSupportVisualEditor', self::guessVE() ) ) {
			$modules[] = 'ext.moderation.ve';
		}

		if ( self::need( 'ModerationSupportMobileFrontend', self::isMobile() ) ) {
			$modules[] = 'ext.moderation.mf.notify';

			if ( version_compare( $wgVersion, '1.33.0', '>=' ) ) {
				// FIXME: must support preload in MobileFrontend for MediaWiki 1.33
				$modules[] = 'ext.moderation.mf.preload33';
			} else {
				// For MediaWiki 1.31-1.32
				$modules[] = 'ext.moderation.mf.preload31';
			}
		}

		if ( $modules || $out->getConfig()->get( 'ModerationForceAjaxHook' ) ) {
			$modules[] = 'ext.moderation.ajaxhook';
			$out->addModules( $modules );
		}
	}
}
