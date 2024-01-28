<?php

namespace Cite;

use MediaWiki\Parser\Sanitizer;
use StatusValue;
use StripState;

/**
 * Context-aware, detailed validation of the arguments and content of a <ref> tag.
 *
 * @license GPL-2.0-or-later
 */
class Validator {

	// This class seems to not use optimally status objects. The idea is to create a status object and
	// cumulate information into it and return it at the end.
	private StripState $stripState;
	private ReferenceStack $referenceStack;
	private ?string $inReferencesGroup;

	/**
	 * @param string|null $inReferencesGroup Group name of the <references> context to consider
	 */
	public function __construct($stripState, $referenceStack, $inReferencesGroup = null) {
		$this->stripState = $stripState;
		$this->referenceStack = $referenceStack;
		$this->inReferencesGroup = $inReferencesGroup;
	}

	public function validateNewRef(?string $text, string $group, ?string $name, string|int $grKey) {
		// Not sure thar it is optimal to return the status as soon  as we have an error.
		// The idea is perhaps to cumulate the errors in the status and return it at the end.
		if (ctype_digit((string) $name)) {
			// Numeric names mess up the resulting id's, potentially producing
			// duplicate id's in the XHTML.  The Right Thing To Do
			// would be to mangle them, but it's not really high-priority
			// (and would produce weird id's anyway).
			$status = StatusValue::newFatal('cite_error_ref_numeric_key');
			$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
		}

		if ($text !== null) {
			$partiallyUntaggedText = preg_replace('#<(\w++)[^>]*+>.*?</\1\s*>|<!--.*?-->#s', '', $text);
			$unTaggedText = preg_replace('#<ref(erences)?\b[^>]*/>#s', '', $partiallyUntaggedText);
			if (preg_match('/<ref(erences)?\b[^>]*+>/i', $unTaggedText)) {
				// (bug T8199) This most likely implies that someone left off the
				// closing </ref> tag, which will cause the entire article to be
				// eaten up until the next closing </ref>.  So we bail out early instead.
				// The fancy regex above first tries chopping out anything that
				// looks like a comment or SGML tag, which is a crude way to avoid
				// false alarms for <nowiki>, <pre>, etc.
				//
				// Possible improvement: print the warning, followed by the contents
				// of the <ref> tag.  This way no part of the article will be eaten
				// even temporarily.
				//
				// This cannot be managed as the other warnings, because it is hard to
				// predict the behaviour of the parser.
				$status = StatusValue::newFatal('cite_error_included_ref');
				$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
			}
		}
	}

	public function validateNewRefInList(?string $text, string $group, ?string $name, string|int $grKey) {
		// Not sure thar it is optimal to return the status as soon  as we have an error.
		// The idea is perhaps to cumulate the errors in the status and return it at the end.
		if ($name === null) {
			// <ref> calls inside <references> must be named
			$status = StatusValue::newFatal('cite_error_references_no_key');
			$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
		}

		if ($group !== $this->inReferencesGroup) {
			// <ref> and <references> have conflicting group attributes.
			$status = StatusValue::newFatal(
					'cite_error_references_group_mismatch',
					Sanitizer::safeEncodeAttribute($group)
			);
			$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
		}
	}

	public function validateNewHalfParsedHtml(?string $halfParsedHtml, bool $inList, string $group, string|int $grKey) {
		// Not sure thar it is optimal to return the status as soon  as we have an error.
		// The idea is perhaps to cumulate the errors in the status and return it at the end.
		if ($inList && ( $halfParsedHtml === null || trim($halfParsedHtml) === '' )) {
			// <ref> called in <references> has no content.
			$status = StatusValue::newFatal(
					'cite_error_empty_references_define',
					Sanitizer::safeEncodeAttribute($grKey),
					Sanitizer::safeEncodeAttribute($group)
			);
			$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
		}

		$storedHalfParsedHtml = $this->referenceStack->getRef($group, $grKey)->text;
		if (isset($storedHalfParsedHtml) && isset($halfParsedHtml) &&
			$this->stripState->unstripBoth($halfParsedHtml) !== $this->stripState->unstripBoth($storedHalfParsedHtml)) {
			$status = StatusValue::newFatal(
					'cite_error_references_duplicate_key',
					Sanitizer::safeEncodeAttribute($grKey)
			);
			$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
		}
	}

	// Only to be executed after all other ref tags for the group have been processed.
	// Otherwise, the count property for the item might not be the final value.
	public function validateGroupReferences(string $group) {
		// Not sure, eventually, if there is more things checked, that it would be optimal to return
		// the status as soon  as we have an error. The idea is perhaps to cumulate the errors
		// in the status and return it at the end.

		$refsGroup = $this->referenceStack->getGroupRefs($this->inReferencesGroup);
		foreach ($refsGroup as $grKey => $ref) {
			if ($ref->count === 0) {
				$status = StatusValue::newFatal(
						'cite_error_references_missing_key',
						Sanitizer::safeEncodeAttribute($grKey),
						Sanitizer::safeEncodeAttribute($group)
				);
				$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
			}

			if ($ref->text === null || trim($ref->text) === '') {
				$status = StatusValue::newFatal(
						'cite_error_references_no_text',
					Sanitizer::safeEncodeAttribute($grKey)
				);
				$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
			}

			if (isset($ref->extends)) {
				$parent = $this->referenceStack->getRef($group, $ref->extends);
				if (isset($parent->extends)) {
					$status = StatusValue::newFatal(
							'cite_error_ref_nested_extends',
							$extends,
							$parent->extends
					);
					$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
				}
			}
		}
	}

	/**	 *
	 * @param Parser $parser
	 * @param bool $isSectionPreview
	 *
	 * @return string HTML
	 */
	public function validateRemainingRef() {
		foreach ($this->referenceStack->getGroups() as $group) {
			foreach ($this->referenceStack->getGroupRefs($group) as $grKey => $ref) {
				$status = StatusValue::newFatal(
					'cite_error_group_refs_without_references',
					Sanitizer::safeEncodeAttribute($group),
					Sanitizer::safeEncodeAttribute($grKey)
				);
				$this->referenceStack->setWarnings($group, $grKey, $status->getErrors());
			}
		}
	}
}
