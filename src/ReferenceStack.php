<?php

namespace Cite;

use LogicException;
use StripState;

/**
 * Encapsulates most of Cite state during parsing.  This includes metadata about each ref tag,
 * and a rollback stack to correct confusion caused by lost context when `{{#tag` is used.
 *
 * @license GPL-2.0-or-later
 */
class ReferenceStack {

	/**
	 * Data structure representing all <ref> tags parsed so far, indexed by group name (an empty
	 * string for the default group) and reference name.
	 *
	 * References without a name get a numeric index, starting from 0. Conflicts are avoided by
	 * disallowing numeric names (e.g. <ref name="1">) in {@see Validator::validateRef}.
	 *
	 * @var array<string,array<string|int,ReferenceStackItem>>
	 */
	private array $refs = [];

	/**
	 * Auto-incrementing sequence number for all <ref>, no matter which group
	 */
	private int $refSequence = 0;

	/** @var int[] Counter for the number of refs in each group */
	private array $groupRefSequence = [];

	/**
	 * <ref> call stack
	 * Used to cleanup out of sequence ref calls created by #tag
	 * See description of function rollbackRef.
	 *
	 * @var (array|false)[]
	 * @phan-var array<array{0:string,1:int,2:string,3:?string,4:?string,5:?string,6:array}|false>
	 */
	private array $refCallStack = [];

	private const ACTION_ASSIGN = 'assign';
	private const ACTION_INCREMENT = 'increment';
	private const ACTION_NEW_FROM_PLACEHOLDER = 'new-from-placeholder';
	private const ACTION_NEW = 'new';

	/**
	 * Register a new ref
	 *
	 * @param string $group
	 * @param ?string $name
	 * @param bool $inList
	 *
	 * @return string|int The group key $grKey of the new ref in the group
	 */
	public function register(string $group, ?string $name, bool $inList): string|int {
		if (!isset($this->refs[$group]) || !isset($name) || !isset($this->refs[$group][$name])) {
			// This is the case where we increment $this->refSequence and $this->groupRefSequence[$group].
			$this->refs[$group] ??= [];
			$this->groupRefSequence[$group] ??= 0;
			$ref = new ReferenceStackItem();
			$ref->count = $inList ? 0 : 1;
			$ref->group = $group;
			$ref->number = ++$this->groupRefSequence[$group];
			$ref->key = ++$this->refSequence;
			if (!$name) {
				// This is an anonymous reference, which will be given a numeric index.
				$this->refs[$group][] = $ref;
			} else {
				// Valid group key with first occurrence
				$ref->name = $name;
				$this->refs[$group][$name] = $ref;
			}
			end($this->refs[$group]);
			$grKey = key($this->refs[$group]);
		} else {
			if (!$inList)
				$this->refs[$group][$name]->count++;
			$grKey = $name;
		}
		return $grKey;
	}

	/**
	 * Set the text for a ref that is identified by its group and key
	 *
	 * @param ?string $text Content from the <ref> tag
	 * @param string $group
	 * @param string|int $grKey
	 *
	 * @return ReferenceStackItem
	 */
	public function setHalfParsedHtml(
		?string $text,
		string $group,
		string|int $grKey,
	): ReferenceStackItem {
		if ($this->refs[$group][$grKey]->text === null && $text !== null) {
			// If no text was set before, use this text
			$this->refs[$group][$grKey]->text = $text;
		}
		// The case elseif $this->refs[$group][$grKey]->text !== $text must be previously managed by validation
		return $this->refs[$group][$grKey];
	}

	/**
	 * Set the direction for a ref that is identified by its group and key
	 *
	 * @param ?string $dir Direction from the <ref> tag
	 * @param string $group
	 * @param string|int $grKey
	 *
	 * @return ReferenceStackItem
	 */
	public function setDir(
		?string $dir,
		string $group,
		string|int $grKey,
	): ReferenceStackItem {
		if ($this->refs[$group][$grKey]->dir === null && $dir !== null && $ref->text === null && $text !== null) {
			// If no dir was set before, use this dir, but only if there is a text.
			$this->refs[$group][$grKey]->dir = $dir;
		}
		// The case $this->refs[$group][$grKey]->dir !== $dir must be previously managed by validation
		return $this->refs[$group][$grKey];
	}

	/**
	 * Set the parent for a ref that is identified by its group and key
	 *
	 * @param ?string $extends parent from the <ref> tag
	 * @param string $group
	 * @param string|int $grKey
	 *
	 * @return ReferenceStackItem
	 */
	public function setExtends(
		?string $extends,
		string $group,
		string|int $grKey,
	): ReferenceStackItem {
		$ref = & $this->refs[$group][$grKey];
		// Do not mess with a known parent a second time
		if ($extends && !isset($ref->extendsIndex)) {
			$parentRef = & $this->refs[$group][$extends];
			if (!isset($parentRef)) {
				// Create a new placeholder and give it the current sequence number.
				$parentRef = new ReferenceStackItem();
				$parentRef->name = $extends;
				$parentRef->number = $ref->number;
				$parentRef->placeholder = true;
			} else {
				$ref->number = $parentRef->number;
				// Roll back the group sequence number.
				--$this->groupRefSequence[$group];
			}
			$parentRef->extendsCount ??= 0;
			$ref->extends = $extends;
			$ref->extendsIndex = ++$parentRef->extendsCount;
		}
		// The case $extends && $ref->extends !== $extends )
		// must be managed in validation
		//	$ref->warnings[] = [ 'cite_error_references_duplicate_key', $name ];
		return $this->refs[$group][$grKey];
	}

	/**
	 * Clear state for a single group.
	 *
	 * @param string $group
	 *
	 * @return array<string|int,ReferenceStackItem> The references from the removed group
	 */
	public function popGroup(string $group): array {
		$refs = $this->getGroupRefs($group);
		unset($this->refs[$group]);
		unset($this->groupRefSequence[$group]);
		return $refs;
	}

	/**
	 * Returns a list of all groups with references.
	 *
	 * @return string[]
	 */
	public function getGroups(): array {
		$groups = [];
		foreach ($this->refs as $group => $refs) {
			if ($refs) {
				$groups[] = $group;
			}
		}
		return $groups;
	}

	/**
	 * Return all references for a group.
	 *
	 * @param string $group
	 *
	 * @return array<string|int,ReferenceStackItem>
	 */
	public function getGroupRefs(string $group): array {
		return $this->refs[$group] ?? [];
	}

	/**
	 * Set warnings.
	 *
	 * @param string $group
	 * @param string|int $grKey
	 * @param string $msg Unwrapped but valid html expressing the warning.
	 *
	 * @return null
	 */
	public function setWarnings(string $group, string|int $grKey, array $warnings) {
		$this->refs[$group][$grKey]->warnings = array_merge($this->refs[$group][$grKey]->warnings, $warnings);
		return null;
	}
}
