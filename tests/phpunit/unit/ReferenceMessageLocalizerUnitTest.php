<?php

namespace Cite\Tests\Unit;

use Cite\ReferenceMessageLocalizer;
use Language;

/**
 * @coversDefaultClass \Cite\ReferenceMessageLocalizer
 */
class ReferenceMessageLocalizerUnitTest extends \MediaWikiUnitTestCase {

	/**
	 * @covers ::localizeSeparators
	 * @covers ::__construct
	 */
	public function testLocalizeSeparators() {
		$mockLanguage = $this->createMock( Language::class );
		$mockLanguage->method( 'separatorTransformTable' )->willReturn( [ '.' => ',', '0' => '' ] );
		$messageLocalizer = new ReferenceMessageLocalizer( $mockLanguage );
		$this->assertSame( '10,0', $messageLocalizer->localizeSeparators( '10.0' ) );
	}

	/**
	 * @covers ::localizeDigits
	 */
	public function testLocalizeDigits() {
		$mockLanguage = $this->createMock( Language::class );
		$mockLanguage->method( 'formatNumNoSeparators' )->willReturn( 'ה' );
		$messageLocalizer = new ReferenceMessageLocalizer( $mockLanguage );
		$this->assertSame( 'ה', $messageLocalizer->localizeDigits( '5' ) );
	}

}
