<?php

class CiteParserTagHooks {

	/**
	 * Enables the two <ref> and <references> tags.
	 *
	 * @param Parser $parser
	 */
	public static function initialize( Parser $parser ) {
		$parser->setHook( 'ref', __CLASS__ . '::ref' );
		$parser->setHook( 'references', __CLASS__ . '::references' );
	}

	/**
	 * Parser hook for the <ref> tag.
	 *
	 * @param string|null $content Raw wikitext content of the <ref> tag.
	 * @param string[] $attributes
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function ref( $content, array $attributes, Parser $parser, PPFrame $frame ) {
		/** @var Cite $cite */
		$cite = $parser->extCite;
		// @phan-suppress-next-line SecurityCheck-XSS False positive
		return $cite->ref( $content, $attributes, $parser, $frame );
	}

	/**
	 * Parser hook for the <references> tag.
	 *
	 * @param string|null $content Raw wikitext content of the <references> tag.
	 * @param string[] $attributes
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function references( $content, array $attributes, Parser $parser, PPFrame $frame ) {
		/** @var Cite $cite */
		$cite = $parser->extCite;
		// @phan-suppress-next-line SecurityCheck-XSS False positive
		return $cite->references( $content, $attributes, $parser, $frame );
	}

}