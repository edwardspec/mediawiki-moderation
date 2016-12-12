<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2016 Edward Chernenko.

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
	@file
	@brief Adds ajaxhook-related JavaScript modules when they are needed.

	Default behavior: automatically check for presence of extension.
	For example, if Extension:VisualEditor is detected,
	then module 'ext.moderation.preload.visualeditor' will be attached.

	This can be overriden in LocalSettings.php:
	$wgModerationSupportVisualEditor = true; - attach even if not detected.
	$wgModerationSupportVisualEditor = false; - don't attach even if detected.
	$wgModerationSupportVisualEditor = "guess"; - default behavior.

	If at least one module is attached (or if $wgModerationForceAjaxHook is
	set to true), "ext.moderation.ajaxhook" will also be attached.
*/

class ModerationAjaxHook {

	/**
		@brief List of known modules and situations when they are needed.
	*/
	static protected function getKnownModules() {
		return array(
			array(
				'ext.moderation.preload.visualeditor', /* ResourceLoader module */
				'ModerationSupportVisualEditor', /* Configuration variable: true, false or "guess" */
				function() { /* How to guess whether this module is needed */
					return class_exists( 'ApiVisualEditorEdit' )
						&& !self::isMobile(); /* Desktop only */
				}
			),
			array(
				'ext.moderation.preload.mobilefrontend',
				'ModerationSupportMobileFrontend',
				function() {
					return self::isMobile();
				}
			)
		);
	}

	/** @brief Convenience method: returns true if in Mobile skin, false otherwise */
	static protected function isMobile() {
		return ( class_exists( 'MobileContext' ) &&
			MobileContext::singleton()->shouldDisplayMobileView());
	}

	/**
		@brief Add needed modules to $out.
	*/
	public static function add( OutputPage &$out ) {
		$needed_modules = array();
		foreach ( self::getKnownModules() as $m ) {
			list( $module_name, $config_key, $guess_fn ) = $m;

			$is_needed = $out->getConfig()->get( $config_key );
			if ( !is_bool( $is_needed ) ) {
				$is_needed = $guess_fn();
			}

			if ( $is_needed ) {
				$needed_modules[] = $module_name;
			}
		}

		if ( $needed_modules || $out->getConfig()->get( 'ModerationForceAjaxHook' ) ) {
			$needed_modules[] = 'ext.moderation.ajaxhook';
			$out->addModules( $needed_modules );
		}
	}
}
