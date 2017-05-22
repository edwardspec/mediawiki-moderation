'use strict';
const Page = require( './page' ),
	nodeUrl = require( 'url' );

/**
	@brief Represents the page visited immediately after editing.
*/

class PostEdit extends Page {

	/** @brief Postedit notification element (as created by mw.notify) */
	get notification() { return this.getWhenExists( '.postedit' ); }

	get notificationText() { return this.notification.getText(); }

	get pendingIcon() { return this.getWhenExists( '#pending-review' ); }

	get notificationLinks() { return this.notification.elements( 'a' ).value; }

	get notificationLinkEdit() {
		return this.parseUrl( this.getWhenExists( 'a=your version of this page' ) );
	}

	get notificationLinkSignup() {
		return this.parseUrl( this.getWhenExists( 'a=sign up' ) );
	}

	/**
		@brief Convenience function: parse the link URL.
	*/
	parseUrl( linkElement ) {
		return nodeUrl.parse(
			linkElement.getAttribute( 'href' ),
			true,
			true
		);
	}
}

module.exports = new PostEdit();
