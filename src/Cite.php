<?php

/**
 * A parser extension that adds two tags, <ref> and <references> for adding
 * citations to pages
 *
 * @ingroup Extensions
 *
 * Documentation
 * @link https://www.mediawiki.org/wiki/Extension:Cite/Cite.php
 *
 * <cite> definition in HTML
 * @link http://www.w3.org/TR/html4/struct/text.html#edef-CITE
 *
 * <cite> definition in XHTML 2.0
 * @link http://www.w3.org/TR/2005/WD-xhtml2-20050527/mod-text.html#edef_text_cite
 *
 * @bug https://phabricator.wikimedia.org/T6579
 *
 * @author Ævar Arnfjörð Bjarmason <avarab@gmail.com>
 * @copyright Copyright © 2005, Ævar Arnfjörð Bjarmason
 * @license GPL-2.0-or-later
 */

namespace Cite;

use LogicException;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Sanitizer;
use Parser;
use StatusValue;
use PPFrame;

/**
 * @license GPL-2.0-or-later
 */
class Cite {

	public const DEFAULT_GROUP = '';

	/**
	 * Wikitext attribute name for Book Referencing.
	 */
	public const BOOK_REF_ATTRIBUTE = 'extends';

	/**
	 * Page property key for the Book Referencing `extends` attribute.
	 */
	public const BOOK_REF_PROPERTY = 'ref-extends';

	private bool $isSectionPreview;
	private FootnoteMarkFormatter $footnoteMarkFormatter;
	private ReferencesFormatter $referencesFormatter;
	private ErrorReporter $errorReporter;

	/**
	 * The depth of recursion in the process of <ref> tags
	 * Used to separate in list tags from the others.
	 */
	private int $depthRef = 0;

	/**
	 * @var null|string The current group name while parsing nested <ref> in <references>. Null when
	 *  parsing <ref> outside of <references>. Warning, an empty string is a valid group name!
	 */
	private ?string $inReferencesGroup = null;

	/**
	 * Error stack used when defining refs in <references>
	 */
	private StatusValue $mReferencesErrors;
	private ReferenceStack $referenceStack;

	public function __construct(Parser $parser) {
		$this->isSectionPreview = $parser->getOptions()->getIsSectionPreview();
		$messageLocalizer = new ReferenceMessageLocalizer($parser->getContentLanguage());
		$this->errorReporter = new ErrorReporter($messageLocalizer);
		$this->mReferencesErrors = StatusValue::newGood();
		$this->referenceStack = new ReferenceStack();
		$anchorFormatter = new AnchorFormatter();
		$this->footnoteMarkFormatter = new FootnoteMarkFormatter(
			$this->errorReporter,
			$anchorFormatter,
			$messageLocalizer
		);
		$this->referencesFormatter = new ReferencesFormatter(
			$this->errorReporter,
			$anchorFormatter,
			$messageLocalizer
		);
	}

	/**
	 * Callback function for <ref>
	 *
	 * @param Parser $parser
	 * @param ?string $text Raw, untrimmed wikitext content of the <ref> tag, if any
	 * @param string[] $argv Arguments as given in <ref name=…>, already trimmed
	 *
	 * @return string|null Null in case a <ref> tag is not allowed in the current context
	 */
	public function ref(Parser $parser, ?string $text, PPFrame $frame, array $argv): ?string {

		$ret = $this->guardedRef($parser, $text, $frame, $argv);

		return $ret;
	}

	/**
	 * @param Parser $parser
	 * @param ?string $text Raw, untrimmed wikitext content of the <ref> tag, if any
	 * @param string[] $argv Arguments as given in <ref name=…>, already trimmed
	 *
	 * @return string HTML
	 */
	private function guardedRef(
		Parser $parser,
		?string $text,
		PPFrame $frame,
		array $argv
	): string {
		// Tag every page where Book Referencing has been used, whether or not the ref tag is valid.
		// This code and the page property will be removed once the feature is stable.  See T237531.
		// Todo: Check that this is still OK with the new code.
		if (array_key_exists(self::BOOK_REF_ATTRIBUTE, $argv)) {
			$parser->getOutput()->setPageProperty(self::BOOK_REF_PROPERTY, '');
		}

		$status = $this->parseArguments(
			$argv,
			['group', 'name', self::BOOK_REF_ATTRIBUTE, 'follow', 'dir']
		);
		$arguments = $status->getValue();
		// Use the default group, or the references group when inside one.
		$arguments['group'] ??= $this->inReferencesGroup ?? self::DEFAULT_GROUP;

		// The parameters must be processed, because they might be template variables.
		// Use the default group, or the references group when inside one.
		$gr = & $arguments['group'];
		if (isset($gr) && $gr) {
			// To process group template variable
			$gr = trim($parser->recursiveTagParse($gr, $frame), ' "');
		}
		unset($gr);

		$nm = & $arguments['name'];
		if (isset($nm) && $nm) {
			// To process name template variable.
			$nm = trim($parser->recursiveTagParse($nm, $frame), '"');
			if (!$nm)
				$nm = null;
		}
		unset($nm);

		// Validation cares about the difference between null and empty, but from here on we don't
		if ($text !== null && trim($text) === '') {
			$text = null;
		}

		$inList = ($this->inReferencesGroup !== null && $this->depthRef == 0);
		$grKey = $this->referenceStack->register($arguments['group'], $arguments['name'], $inList);

		// This is not only a shortcut : a value null is not accepted when a string is expected.
		$processed_text = $text;
		if ($text !== null) {
			$this->depthRef++;
			$processed_text = $parser->recursiveTagParse($text, $frame);
			$this->depthRef--;
		}

		// Because of template variables that might be empty, we repeat.
		if ($processed_text !== null && trim($processed_text) === '') {
			$processed_text = null;
		}

		$ref = $this->referenceStack->setHalfParsedHtml($processed_text, $arguments['group'], $grKey);
		if (isset($arguments['dir'])) {
			$ref = $this->referenceStack->setDir($arguments['dir'], $arguments['group'], $grKey);
		}
		if (isset($arguments[self::BOOK_REF_ATTRIBUTE])) {
			$ref = $this->referenceStack->setExtends($arguments[self::BOOK_REF_ATTRIBUTE], $arguments['group'], $grKey);
		}
		return $inList ? '' : $this->footnoteMarkFormatter->linkRef($parser, $arguments['group'], $ref);
	}

	/**
	 * @param string[] $argv The argument vector
	 * @param string[] $allowedAttributes Allowed attribute names
	 *
	 * @return StatusValue Either an error, or has a value with the dictionary of field names and
	 * parsed or default values.  Missing attributes will be `null`.
	 */
	private function parseArguments(array $argv, array $allowedAttributes): StatusValue {
		$expected = count($allowedAttributes);
		$allValues = array_merge(array_fill_keys($allowedAttributes, null), $argv);
		if (isset($allValues['dir'])) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullableInternal False positive
			$allValues['dir'] = strtolower($allValues['dir']);
		}

		$status = StatusValue::newGood(array_slice($allValues, 0, $expected));

		if (count($allValues) > $expected) {
			// A <ref> must have a name (can be null), but <references> can't have one
			$status->fatal(in_array('name', $allowedAttributes, true) ? 'cite_error_ref_too_many_keys' : 'cite_error_references_invalid_parameters'
			);
		}

		return $status;
	}

	/**
	 * Callback function for <references>
	 *
	 * @param Parser $parser
	 * @param ?string $text Raw, untrimmed wikitext content of the <references> tag, if any
	 * @param string[] $argv Arguments as given in <references …>, already trimmed
	 *
	 * @return string|null Null in case a <references> tag is not allowed in the current context
	 */
	public function references(Parser $parser, ?string $text, PPFrame $frame, array $argv): ?string {

		$status = $this->parseArguments($argv, ['group', 'responsive']);
		$arguments = $status->getValue();

		$gr = & $arguments['group'];
		if (isset($gr) && $gr) {
			// To process group template variable
			$gr = trim($parser->recursiveTagParse($gr, $frame), ' "');
		}
		unset($gr);

		$rp = & $arguments['responsive'];
		if (isset($rp) && $rp) {
			// To process responsive template variable
			$rp = trim($parser->recursiveTagParse($rp, $frame), ' "');
		}
		unset($rp);

		$this->inReferencesGroup = $arguments['group'] ?? self::DEFAULT_GROUP;

		$this->parseReferencesTagContent($parser, $text, $frame);

		$responsive = $arguments['responsive'];
		$ret = $this->formatReferences($parser, $this->inReferencesGroup, $responsive);
		$this->inReferencesGroup = null;

		return $ret;
	}

	/**
	 * @param Parser $parser
	 * @param ?string $text Raw, untrimmed wikitext content of the <references> tag, if any
	 *
	 * @return StatusValue
	 */
	private function parseReferencesTagContent(Parser $parser, ?string $text, PPFrame $frame): StatusValue {
		// Nothing to parse in an empty <references /> tag
		if ($text === null || trim($text) === '') {
			return StatusValue::newGood();
		}
		// Parse the <references> content to process any unparsed <ref> tags, but drop the resulting
		// HTML
		$ptext = $parser->recursiveTagParse($text, $frame);

		return StatusValue::newGood();
	}

	private function formatReferencesErrors(Parser $parser): string {
		$html = '';
		foreach ($this->mReferencesErrors->getErrors() as $error) {
			if ($html) {
				$html .= "<br />\n";
			}
			$html .= $this->errorReporter->halfParsed($parser, $error['message'], ...$error['params']);
		}
		$this->mReferencesErrors = StatusValue::newGood();
		return $html ? "\n$html" : '';
	}

	/**
	 * @param Parser $parser
	 * @param string $group
	 * @param string|null $responsive Defaults to $wgCiteResponsiveReferences when not set
	 *
	 * @return string HTML
	 */
	private function formatReferences(
		Parser $parser,
		string $group,
		string $responsive = null
	): string {
		global $wgCiteResponsiveReferences;

		return $this->referencesFormatter->formatReferences(
				$parser,
				$this->referenceStack->popGroup($group),
				$responsive !== null ? $responsive !== '0' : $wgCiteResponsiveReferences,
				$this->isSectionPreview
		);
	}

	/**
	 * Called at the end of page processing to append a default references
	 * section, if refs were used without a main references tag. If there are references
	 * in a custom group, and there is no references tag for it, show an error
	 * message for that group.
	 * If we are processing a section preview, this adds the missing
	 * references tags and does not add the errors.
	 *
	 * @param Parser $parser
	 * @param bool $isSectionPreview
	 *
	 * @return string HTML
	 */
	public function checkRefsNoReferences(Parser $parser, bool $isSectionPreview): string {
		global $wgCiteResponsiveReferences;
		$groups = $this->referenceStack->getGroups();
		$s = '';
		foreach ($groups as $group) {
			$remainingRefs = $this->referenceStack->getGroupRefs($group);
			$formattedRefs = $this->referencesFormatter->formatReferences(
				$parser,
				$remainingRefs,
				$wgCiteResponsiveReferences,
				$this->isSectionPreview
			);
			$s .= $formattedRefs;
		}
		if ($isSectionPreview && $s) {
			$headerMsg = wfMessage('cite_section_preview_references');
			if (!$headerMsg->isDisabled()) {
				$s = Html::element(
						'h2',
						['id' => 'mw-ext-cite-cite_section_preview_references_header'],
						$headerMsg->text()
					) . $s;
			}
		}
		return $s;
	}

	/**
	 * @see https://phabricator.wikimedia.org/T240248
	 * @return never
	 */
	public function __clone() {
		throw new LogicException('Create a new instance please');
	}
}
