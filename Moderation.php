<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2016 Edward Chernenko.

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
	@ingroup Extensions
	@link https://mediawiki.org/wiki/Extension:Moderation
*/

if ( !defined( 'MEDIAWIKI' ) ) {
        echo <<<EOT
To install this extension, put the following line in LocalSettings.php:
require_once( "\$IP/extensions/Moderation/Moderation.php" );
EOT;
        exit( 1 );
}

$wgExtensionCredits['antispam'][] = array(
	'path' => __FILE__,
	'name' => 'Moderation',
	'author' => 'Edward Chernenko',
	'url' => 'https://www.mediawiki.org/wiki/Extension:Moderation',
	'descriptionmsg' => 'moderation-desc',
	'version' => '7dec2016-4'
);

$wgMessagesDirs['Moderation'] = array(
	__DIR__ . "/i18n",
	__DIR__ . "/api/i18n"
);
$wgExtensionMessagesFiles['ModerationAlias'] = __DIR__ . '/Moderation.alias.php';

$wgAutoloadClasses['SpecialModeration'] = __DIR__ . '/SpecialModeration.php';
$wgAutoloadClasses['ApiQueryModerationPreload'] = __DIR__ . '/api/ApiQueryModerationPreload.php';
$wgAutoloadClasses['ModerationLogFormatter'] = __DIR__ . '/ModerationLogFormatter.php';
$wgAutoloadClasses['ModerationSpecialUpload'] = __DIR__ . '/ModerationSpecialUpload.php';
$wgAutoloadClasses['ModerationAjaxHook'] = __DIR__ . '/util/ModerationAjaxHook.php';
$wgAutoloadClasses['ModerationBlockCheck'] = __DIR__ . '/util/ModerationBlockCheck.php';
$wgAutoloadClasses['ModerationCanSkip'] = __DIR__ . '/util/ModerationCanSkip.php';
$wgAutoloadClasses['ModerationCheckUserHook'] = __DIR__ . '/hooks/ModerationCheckUserHook.php';
$wgAutoloadClasses['ModerationPreload'] = __DIR__ . '/hooks/ModerationPreload.php';
$wgAutoloadClasses['ModerationEditHooks'] = __DIR__ . '/hooks/ModerationEditHooks.php';
$wgAutoloadClasses['ModerationUploadHooks'] = __DIR__ . '/hooks/ModerationUploadHooks.php';
$wgAutoloadClasses['ModerationUpdater'] = __DIR__ . '/hooks/ModerationUpdater.php';
$wgAutoloadClasses['ModerationAction'] = __DIR__ . '/action/ModerationAction.php';
$wgAutoloadClasses['ModerationActionShow'] = __DIR__ . '/action/ModerationActionShow.php';
$wgAutoloadClasses['ModerationActionShowImage'] = __DIR__ . '/action/ModerationActionShowImage.php';
$wgAutoloadClasses['ModerationActionBlock'] = __DIR__ . '/action/ModerationActionBlock.php';
$wgAutoloadClasses['ModerationActionApprove'] = __DIR__ . '/action/ModerationActionApprove.php';
$wgAutoloadClasses['ModerationActionReject'] = __DIR__ . '/action/ModerationActionReject.php';
$wgAutoloadClasses['ModerationActionMerge'] = __DIR__ . '/action/ModerationActionMerge.php';
$wgAutoloadClasses['ModerationActionPreview'] = __DIR__ . '/action/ModerationActionPreview.php';

$wgHooks['AddNewAccount'][] = 'ModerationPreload::onAddNewAccount';
$wgHooks['AlternateEdit'][] = 'ModerationPreload::onAlternateEdit';
$wgHooks['ApiCheckCanExecute'][] = 'ModerationUploadHooks::onApiCheckCanExecute';
$wgHooks['AuthPluginAutoCreate'][] = 'ModerationEditHooks::onAuthPluginAutoCreate';
$wgHooks['BeforePageDisplay'][] = 'ModerationEditHooks::onBeforePageDisplay';
$wgHooks['EditFilter'][] = 'ModerationEditHooks::onEditFilter';
$wgHooks['EditFormInitialText'][] = 'ModerationPreload::onEditFormInitialText';
$wgHooks['EditFormPreloadText'][] = 'ModerationPreload::onEditFormPreloadText';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'ModerationUpdater::onLoadExtensionSchemaUpdates';
$wgHooks['PageContentSaveComplete'][] = 'ModerationEditHooks::onPageContentSaveComplete';
$wgHooks['PageContentSave'][] = 'ModerationEditHooks::onPageContentSave';
$wgHooks['EditPage::showEditForm:fields'][] = 'ModerationEditHooks::PrepareEditForm';
$wgHooks['UploadVerifyFile'][] = 'ModerationUploadHooks::onUploadVerifyFile';
$wgHooks['getUserPermissionsErrors'][] = 'ModerationUploadHooks::ongetUserPermissionsErrors';

$wgSpecialPages['Moderation'] = 'SpecialModeration';
$wgAPIPropModules['moderationpreload'] = 'ApiQueryModerationPreload';

$moduleTemplate = array(
	'localBasePath' => __DIR__ . '/modules',
	'remoteExtPath' => 'Moderation/modules',
	'position' => 'bottom'
);
$wgResourceModules['ext.moderation'] = $moduleTemplate + array( 'styles' => 'ext.moderation.css' );
$wgResourceModules['ext.moderation.edit'] = $moduleTemplate + array(
	'styles' => 'ext.moderation.edit.css'
);
$wgResourceModules['ext.moderation.ajaxhook'] = $moduleTemplate + array(
	'scripts' => 'ext.moderation.ajaxhook.js',
	'targets' => [ 'desktop', 'mobile' ],
	'dependencies' => [ 'ext.moderation.notify' ]
);
$wgResourceModules['ext.moderation.preload.visualeditor'] = $moduleTemplate + array(
	'scripts' => 'ext.moderation.preload.visualeditor.js',
	'targets' => [ 'desktop' ],
	'dependencies' => [ 'mediawiki.api', 'ext.visualEditor.targetLoader' ]
);
$wgResourceModules['ext.moderation.preload.mobilefrontend'] = $moduleTemplate + array(
	'scripts' => 'ext.moderation.preload.mobilefrontend.js',
	'targets' => [ 'mobile' ],
	'dependencies' => [ 'mediawiki.api', 'mobile.editor.api' ]
);
$wgResourceModules['ext.moderation.notify'] = $moduleTemplate + array(
	'scripts' => 'ext.moderation.notify.js',
	'dependencies' => array(
		'mediawiki.jqueryMsg',
		'mediawiki.user',
		'mediawiki.util'
	),
        'messages' => array( 'moderation-edit-queued', 'moderation-suggest-signup' ),
        'targets' => array( 'desktop', 'mobile' )
);

$wgLogTypes[] = 'moderation';
$wgLogActionsHandlers['moderation/*'] = 'ModerationLogFormatter';
$wgLogRestrictions["moderation"] = 'moderation';

$wgAvailableRights[] = 'skip-moderation';
$wgAvailableRights[] = 'moderation';

$wgGroupPermissions['automoderated']['skip-moderation'] = true; # Able to skip moderation
$wgGroupPermissions['moderator']['moderation'] = true; # Able to use Special:Moderation
$wgGroupPermissions['checkuser']['moderation-checkuser'] = true; # Able to see IPs of registered users on Special:Moderation

#
# Below are the configuration options which can be modified in LocalSettings.php.
#
$wgModerationEnable = true; # If "false", new edits are applied as usual (not sent to moderation).

# Time (in seconds) after which rejected edit could no longer be approved
$wgModerationTimeToOverrideRejection = 2 * 7 * 24 * 3600; # 2 weeks

# $wgModerationPreviewLink: if true, "preview" link is shown for pending edits.
#
# NOTE: default value is false. If you need this option, this likely means
# you are NOT following the recommended moderation process (!), because edits
# shouldn't be rejected based on how they are formatted (this offends users),
# only based on their content (which you should see in Diff, not Preview).
# Instead bad-formatted edits should be accepted and then reverted as usual.
# This option was only implemented for paper publications (where formatting
# is more important). This is NEITHER NEEDED NOR RECOMMENDED in most wikis.
# Keep this option at 'false' unless you know you need this.

$wgModerationPreviewLink = false;

# Administrator notification settings
# $wgModerationNotificationEnable - enable or disable notifications
# $wgModerationNotificationNewOnly - notify administrator only about new pages requests
# $wgModerationEmail - email to send notifications

$wgModerationNotificationEnable = false;
$wgModerationNotificationNewOnly = false;
$wgModerationEmail = $wgEmergencyContact;

$wgModerationSupportVisualEditor = "guess"; /* Auto-detect */
$wgModerationSupportMobileFrontend = "guess"; /* Auto-detect */
$wgModerationForceAjaxHook = false; /* Set to true if some unknown-to-us extension has an API-based JavaScript editor */
