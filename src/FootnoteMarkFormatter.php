<?php

namespace Cite;

use MediaWiki\MediaWikiServices;
use Parser;
use Sanitizer;

/**
 * @license GPL-2.0-or-later
 */
class FootnoteMarkFormatter {

	/**
	 * The links to use per group, in order.
	 *
	 * @var (string[]|false)[]
	 */
	private $linkLabels = [];

	/**
	 * @var Parser
	 */
	private $parser;

	/**
	 * @var CiteKeyFormatter
	 */
	private $citeKeyFormatter;

	/**
	 * @var CiteErrorReporter
	 */
	private $errorReporter;

	/**
	 * @param Parser $parser
	 * @param CiteErrorReporter $errorReporter
	 * @param CiteKeyFormatter $citeKeyFormatter
	 */
	public function __construct(
		Parser $parser,
		CiteErrorReporter $errorReporter,
		CiteKeyFormatter $citeKeyFormatter
	) {
		$this->parser = $parser;
		$this->citeKeyFormatter = $citeKeyFormatter;
		$this->errorReporter = $errorReporter;
	}

	/**
	 * Generate a link (<sup ...) for the <ref> element from a key
	 * and return XHTML ready for output
	 *
	 * @suppress SecurityCheck-DoubleEscaped
	 * @param string $group
	 * @param string $key The key for the link
	 * @param ?string $count The index of the key, used for distinguishing
	 *                   multiple occurrences of the same key
	 * @param int|string $label The label to use for the link, I want to
	 *                   use the same label for all occurrences of
	 *                   the same named reference.
	 * @param string|null $subkey
	 *
	 * @return string
	 */
	public function linkRef(
		string $group,
		string $key,
		?string $count,
		$label,
		?string $subkey
	) : string {
		return $this->parser->recursiveTagParse(
			wfMessage(
				'cite_reference_link',
				$this->citeKeyFormatter->refKey( $key, $count ),
				$this->citeKeyFormatter->getReferencesKey( $key . $subkey ),
				Sanitizer::safeEncodeAttribute(
					$this->getLinkLabel( $label, $group,
						( ( $group === Cite::DEFAULT_GROUP ) ? '' : "$group " ) .
							MediaWikiServices::getInstance()->getContentLanguage()
								->formatNum( $label ) )
				)
			)->inContentLanguage()->plain()
		);
	}

	/**
	 * Generate a custom format link for a group given an offset, e.g.
	 * the second <ref group="foo"> is b if $this->mLinkLabels["foo"] =
	 * [ 'a', 'b', 'c', ...].
	 * Return an error if the offset > the # of array items
	 *
	 * @param int|string $offset
	 * @param string $group The group name
	 * @param string $label The text to use if there's no message for them.
	 *
	 * @return string
	 */
	private function getLinkLabel( $offset, string $group, string $label ) : string {
		$message = "cite_link_label_group-$group";
		if ( !isset( $this->linkLabels[$group] ) ) {
			$this->genLinkLabels( $group, $message );
		}
		if ( $this->linkLabels[$group] === false ) {
			// Use normal representation, ie. "$group 1", "$group 2"...
			return $label;
		}

		// FIXME: This doesn't work as expected when $offset is a string like "1.2"
		return $this->linkLabels[$group][$offset - 1]
			?? $this->errorReporter->plain( 'cite_error_no_link_label_group', [ $group, $message ] );
	}

	/**
	 * Generate the labels to pass to the
	 * 'cite_reference_link' message instead of numbers, the format is an
	 * arbitrary number of tokens separated by whitespace.
	 *
	 * @param string $group
	 * @param string $message
	 */
	private function genLinkLabels( string $group, string $message ) {
		$text = false;
		$msg = wfMessage( $message )->inContentLanguage();
		if ( $msg->exists() ) {
			$text = $msg->plain();
		}
		$this->linkLabels[$group] = $text ? preg_split( '/\s+/', $text ) : false;
	}
}