<?php
/**
 * @author Santhosh Thottingal
 * @copyright Copyright © 2012, Santhosh Thottingal
 * @file
 */

/** Tests for MediaWiki languages/classes/LanguageLn.php */
class LanguageLnTest extends LanguageClassesTestCase {
	/** @dataProvider providePlural */
	function testPlural( $result, $value ) {
		$forms = array( 'one', 'other' );
		$this->assertEquals( $result, $this->getLang()->convertPlural( $value, $forms ) );
	}

	/** @dataProvider providePlural */
	function testGetPluralRuleType( $result, $value ) {
		$this->assertEquals( $result, $this->getLang()->getPluralRuleType( $value ) );
	}

	function providePlural() {
		return array(
			array( 'one', 0 ),
			array( 'one', 1 ),
			array( 'other', 2 ),
			array( 'other', 200 ),
		);
	}
}
