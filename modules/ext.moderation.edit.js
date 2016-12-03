/*
	When non-automoderated user does subsequent edits in a new article,
	preserve the edit comment in #wpSummary field.

	See also: ModerationPreload::showUnmoderatedEdit().
*/

( function ( mw, $ ) {

	var $s = $('#wpSummary'),
		summary = mw.config.get('wgPreloadedSummary');

	if(summary && !$s.val()) {
		$s.val(summary);
	}

}( mediaWiki, jQuery ) );
