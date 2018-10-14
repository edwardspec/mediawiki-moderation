<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2018 Edward Chernenko.

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
 * Implements HTML parsing methods for the automated testsuite.
 */

class ModerationTestsuiteHTML extends DOMDocument {

	/**
	 * @const Libxml error code for "unknown tag"
	 * @see http://www.xmlsoft.org/html/libxml-xmlerror.html
	 */
	const XML_HTML_UNKNOWN_TAG = 801;

	/** @const Libxml error code for "tag name mismatch" */
	const XML_ERR_TAG_NAME_MISMATCH = 76;

	/** @var ModerationTestsuiteEngine */
	protected $engine;

	function __construct( ModerationTestsuiteEngine $engine = null ) {
		$this->engine = $engine;
	}

	public function loadFromURL( $url ) {
		if ( !$url ) {
			return null;
		}

		if ( !$this->engine ) {
			throw new MWException(
				"This ModerationTestsuiteHTML object can't use loadFromUrl(), " .
				"it was created without ModerationTestsuiteEngine." );
		}

		$this->engine->ignoreHttpError( 404 );
		$req = $this->engine->httpGet( $url );
		$this->engine->stopIgnoringHttpError( 404 );

		return $this->loadFromReq( $req );
	}

	public function loadFromReq( ModerationTestsuiteResponse $req ) {
		return $this->loadFromString( $req->getContent() );
	}

	/**
	 * Utility function to warn about Libxml errors.
	 * Ignores the fact that some HTML5 tags are unknown to libxml2.
	 */
	public static function checkLibxmlErrors() {
		$errors = libxml_get_errors();
		foreach ( $errors as $error ) {
			if ( $error->code == self::XML_HTML_UNKNOWN_TAG ) {
				/* Ignore: libxml considers modern tags like <bdi> to be errors */
				continue;
			}

			if (
				ModerationTestsuite::mwVersionCompare( '1.28.0', '<=' ) &&
				$error->code == self::XML_ERR_TAG_NAME_MISMATCH &&
				( strpos( $error->message, ': input' ) !== false )
			) {
				/* Ignore: "Unexpected end tag : input" in MediaWiki 1.27-1.28 */
				continue;
			}

			print "LibXML error: " . trim( $error->message ) . "\n";
		}

		libxml_clear_errors();
	}

	public function loadFromString( $string ) {
		// Forget any unhandled errors from previous LibXML parse attempts.
		libxml_clear_errors();

		// Ignore "unknown tag" error, see checkLibxmlErrors() for details.
		libxml_use_internal_errors( true );

		$this->loadHTML( $string );
		self::checkLibxmlErrors();

		return $this;
	}

	/**
	 * Returns the text of the <title> tag.
	 */
	public function getTitle( $url = null ) {
		$this->loadFromURL( $url );

		return $this->
			getElementsByTagName( 'title' )->item( 0 )->textContent;
	}

	/**
	 * Returns HTML element of the error message shown by Moderation.
	 */
	public function getModerationError( $url = null ) {
		$this->loadFromURL( $url );

		$elem = $this->getElementById( 'mw-mod-error' );
		if ( !$elem ) {
			return null;
		}

		return $elem->textContent;
	}

	/**
	 * Returns HTML element of the main text of the page.
	 */
	public function getMainContent( $url = null ) {
		$this->loadFromURL( $url );

		return $this->getElementById( 'mw-content-text' );
	}

	/**
	 * Returns main text of the page (without navigation, etc.).
	 */
	public function getMainText( $url = null ) {
		$elem = $this->getMainContent( $url );

		return trim( $elem->textContent );
	}

	/**
	 *  Returns the text of the notice "You have new messages".
	 */
	public function getNewMessagesNotice( $url = null ) {
		$this->loadFromURL( $url );
		$elem = $this->getElementByXPath( '//*[@class="usermessage"]' );

		if ( !$elem ) {
			return null;
		}

		return $elem->textContent;
	}

	/**
	 *  Returns the text of the notice "You have new messages".
	 */
	public function getSubmitButton( $url = null ) {
		$this->loadFromURL( $url );
		return $this->getElementByXPath( '//*[@id="mw-content-text"]//*[@type="submit"]' );
	}

	/**
	 * Find the DOM element by XPath selector.
		E.g. $t->html->getElementsByXPath( '//row[@name="wpSummary"]' )
	 * @return DOMElement
	 */
	public function getElementByXPath( $selector ) {
		$result = $this->getElementsByXPath( $selector );
		return $result->item( 0 );
	}

	/**
	 * Find all DOM elements matching the XPath selector.
	 * E.g. $t->html->getElementsByXPath( '//a[@class="new"]' )
	 * @return DOMNodeList
	 */
	public function getElementsByXPath( $selector ) {
		$xpath = new DomXpath( $this );
		return $xpath->query( $selector );
	}

	/**
	 * Fetch the edit form and return the text in #wpTextbox1.
	 * @param string $title The page to be opened for editing.
	 */
	public function getPreloadedText( $title ) {
		$url = wfAppendQuery( wfScript( 'index' ), [
			'title' => $title,
			'action' => 'edit'
		] );
		$this->loadFromURL( $url );

		$elem = $this->getElementById( 'wpTextbox1' );
		if ( !$elem )
			return null;

		return trim( $elem->textContent );
	}

	/**
	 * Return the list of ResourceLoader modules which are used in the last fetched HTML.
	 */
	public function getLoaderModulesList( $url = null ) {
		$this->loadFromURL( $url );
		$scripts = $this->getElementsByTagName( 'script' );

		$list = [];
		foreach ( $scripts as $script ) {
			$matches = null;
			if ( preg_match(
				'/(?:mw\.loader\.load\(|RLPAGEMODULES=)\[([^]]+)\]/',
				$script->textContent,
				$matches
			) ) {
				$items = explode( ',', $matches[1] );

				foreach ( $items as $item ) {
					$list[] = preg_replace( '/^"(.*)"$/', '$1', $item );
				}
			}
		}
		return array_unique( $list );
	}

	/**
	 * Return the array of <input> elements in the form (name => value).
	 */
	public function getFormElements( $formElement = null, $url = null ) {
		$this->loadFromURL( $url );

		if ( !$formElement ) {
			$formElement = $this;
		}

		$inputs = $formElement->getElementsByTagName( 'input' );
		$result = [];
		foreach ( $inputs as $input ) {
			$name = $input->getAttribute( 'name' );
			$value = $input->getAttribute( 'value' );

			$result[$name] = $value;
		}
		return $result;
	}
}
