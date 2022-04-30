<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2022 Edward Chernenko.

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

class ModerationTestHTML extends DOMDocument {

	/**
	 * @const Libxml error code for "unknown tag"
	 * @see http://www.xmlsoft.org/html/libxml-xmlerror.html
	 */
	private const XML_HTML_UNKNOWN_TAG = 801;

	/**
	 * Utility function to warn about Libxml errors.
	 * Ignores the fact that some HTML5 tags are unknown to libxml2.
	 *
	 * @return bool True if there were errors, false otherwise.
	 */
	public static function checkLibxmlErrors() {
		$errorCount = 0;
		$errors = libxml_get_errors();
		foreach ( $errors as $error ) {
			if ( $error->code == self::XML_HTML_UNKNOWN_TAG ) {
				/* Ignore: libxml considers modern tags like <bdi> to be errors */
				continue;
			}

			print "LibXML error: " . trim( $error->message ) . "\n";
			$errorCount++;
		}

		libxml_clear_errors();
		return $errorCount > 0;
	}

	/**
	 * Load the HTML document from string that contains the HTML.
	 * @param string $string
	 * @return static
	 */
	public function loadString( $string ) {
		// Forget any unhandled errors from previous LibXML parse attempts.
		libxml_clear_errors();

		// Ignore "unknown tag" error, see checkLibxmlErrors() for details.
		libxml_use_internal_errors( true );

		$this->loadHTML( $string );
		if ( self::checkLibxmlErrors() ) {
			print "LibXML failed to parse the following document: <<<\n" . trim( $string ) . "\n>>>\n";
		}

		return $this;
	}

	/**
	 * Returns the text of the <title> tag.
	 * @return string|null
	 */
	public function getTitle() {
		$titleTag = $this->getElementsByTagName( 'title' )->item( 0 );
		return $titleTag ? $titleTag->textContent : null;
	}

	/**
	 * Returns HTML element of the error message shown by Moderation.
	 * @return string|null
	 */
	public function getModerationError() {
		$elem = $this->getElementById( 'mw-mod-error' );
		return $elem ? $elem->textContent : null;
	}

	/**
	 * Returns HTML element of the main text of the page.
	 * @return DomElement|null
	 */
	public function getMainContent() {
		return $this->getElementById( 'mw-content-text' );
	}

	/**
	 * Returns main text of the page (without navigation, etc.).
	 * @return string|null
	 */
	public function getMainText() {
		$elem = $this->getMainContent();
		return $elem ? trim( $elem->textContent ) : null;
	}

	/**
	 * Returns the text of the notice "You have new messages".
	 * @return string|null
	 */
	public function getNewMessagesNotice() {
		$elem = $this->getElementByXPath( '//*[@class="usermessage"]' );
		return $elem ? $elem->textContent : null;
	}

	/**
	 * Returns the Submit button element.
	 * @return DOMElement
	 */
	public function getSubmitButton() {
		return $this->getElementByXPath( '//*[@id="mw-content-text"]//*[@type="submit"]' );
	}

	/**
	 * Find the DOM element by XPath selector.
	 * E.g. $t->html->getElementsByXPath( '//row[@name="wpSummary"]' )
	 * @param string $selector
	 * @return DOMElement|null
	 */
	public function getElementByXPath( $selector ) {
		$elem = $this->getElementsByXPath( $selector )->item( 0 );

		'@phan-var DOMElement|null $elem';
		return $elem;
	}

	/**
	 * Find all DOM elements matching the XPath selector.
	 * E.g. $t->html->getElementsByXPath( '//a[@class="new"]' )
	 * @param string $selector
	 * @return DOMNodeList
	 */
	public function getElementsByXPath( $selector ) {
		$xpath = new DomXpath( $this );
		return $xpath->query( $selector );
	}

	/**
	 * Return the list of ResourceLoader modules which are used in the last fetched HTML.
	 * @return string[]
	 */
	public function getLoaderModulesList() {
		$list = [];
		foreach ( $this->getElementsByTagName( 'script' ) as $script ) {
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
	 * @param DomElement|null $formElement
	 * @return DomElement[]
	 */
	public function getFormElements( DomElement $formElement = null ) {
		if ( !$formElement ) {
			$formElement = $this;
		}

		$result = [];
		foreach ( $formElement->getElementsByTagName( 'input' ) as $input ) {
			$name = $input->getAttribute( 'name' );
			$value = $input->getAttribute( 'value' );

			$result[$name] = $value;
		}
		return $result;
	}
}
