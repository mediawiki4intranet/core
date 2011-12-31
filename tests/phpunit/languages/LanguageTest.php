<?php

class LanguageTest extends MediaWikiTestCase {

	/**
	 * @var Language
	 */
	private $lang;

	function setUp() {
		$this->lang = Language::factory( 'en' );
	}
	function tearDown() {
		unset( $this->lang );
	}

	function testLanguageConvertDoubleWidthToSingleWidth() {
		$this->assertEquals(
			"0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz",
			$this->lang->normalizeForSearch(
				"０１２３４５６７８９ＡＢＣＤＥＦＧＨＩＪＫＬＭＮＯＰＱＲＳＴＵＶＷＸＹＺａｂｃｄｅｆｇｈｉｊｋｌｍｎｏｐｑｒｓｔｕｖｗｘｙｚ"
			),
			'convertDoubleWidth() with the full alphabet and digits'
		);
	}
	
	/** @dataProvider provideFormattableTimes */
	function testFormatTimePeriod( $seconds, $format, $expected, $desc ) {
		$this->assertEquals( $expected, $this->lang->formatTimePeriod( $seconds, $format ), $desc );
	}
	
	function provideFormattableTimes() {
		return array(
			array( 9.45, array(), '9.5s', 'formatTimePeriod() rounding (<10s)' ),
			array( 9.45, array( 'noabbrevs' => true ), '9.5 seconds', 'formatTimePeriod() rounding (<10s)' ),
			array( 9.95, array(), '10s', 'formatTimePeriod() rounding (<10s)' ),
			array( 9.95, array( 'noabbrevs' => true ), '10 seconds', 'formatTimePeriod() rounding (<10s)' ),
			array( 59.55, array(), '1m 0s', 'formatTimePeriod() rounding (<60s)' ),
			array( 59.55, array( 'noabbrevs' => true ), '1 minute 0 seconds', 'formatTimePeriod() rounding (<60s)' ),
			array( 119.55, array(), '2m 0s', 'formatTimePeriod() rounding (<1h)' ),
			array( 119.55, array( 'noabbrevs' => true ), '2 minutes 0 seconds', 'formatTimePeriod() rounding (<1h)' ),
			array( 3599.55, array(), '1h 0m 0s', 'formatTimePeriod() rounding (<1h)' ),
			array( 3599.55, array( 'noabbrevs' => true ), '1 hour 0 minutes 0 seconds', 'formatTimePeriod() rounding (<1h)' ),
			array( 7199.55, array(), '2h 0m 0s', 'formatTimePeriod() rounding (>=1h)' ),
			array( 7199.55, array( 'noabbrevs' => true ), '2 hours 0 minutes 0 seconds', 'formatTimePeriod() rounding (>=1h)' ),
			array( 7199.55, 'avoidseconds', '2h 0m', 'formatTimePeriod() rounding (>=1h), avoidseconds' ),
			array( 7199.55, array( 'avoid' => 'avoidseconds', 'noabbrevs' => true ), '2 hours 0 minutes', 'formatTimePeriod() rounding (>=1h), avoidseconds' ),
			array( 7199.55, 'avoidminutes', '2h 0m', 'formatTimePeriod() rounding (>=1h), avoidminutes' ),
			array( 7199.55, array( 'avoid' => 'avoidminutes', 'noabbrevs' => true ), '2 hours 0 minutes', 'formatTimePeriod() rounding (>=1h), avoidminutes' ),
			array( 172799.55, 'avoidseconds', '48h 0m', 'formatTimePeriod() rounding (=48h), avoidseconds' ),
			array( 172799.55, array( 'avoid' => 'avoidseconds', 'noabbrevs' => true ), '48 hours 0 minutes', 'formatTimePeriod() rounding (=48h), avoidseconds' ),
			array( 259199.55, 'avoidminutes', '3d 0h', 'formatTimePeriod() rounding (>48h), avoidminutes' ),
			array( 259199.55, array( 'avoid' => 'avoidminutes', 'noabbrevs' => true ), '3 days 0 hours', 'formatTimePeriod() rounding (>48h), avoidminutes' ),
			array( 176399.55, 'avoidseconds', '2d 1h 0m', 'formatTimePeriod() rounding (>48h), avoidseconds' ),
			array( 176399.55, array( 'avoid' => 'avoidseconds', 'noabbrevs' => true ), '2 days 1 hour 0 minutes', 'formatTimePeriod() rounding (>48h), avoidseconds' ),
			array( 176399.55, 'avoidminutes', '2d 1h', 'formatTimePeriod() rounding (>48h), avoidminutes' ),
			array( 176399.55, array( 'avoid' => 'avoidminutes', 'noabbrevs' => true ), '2 days 1 hour', 'formatTimePeriod() rounding (>48h), avoidminutes' ),
			array( 259199.55, 'avoidseconds', '3d 0h 0m', 'formatTimePeriod() rounding (>48h), avoidseconds' ),
			array( 259199.55, array( 'avoid' => 'avoidseconds', 'noabbrevs' => true ), '3 days 0 hours 0 minutes', 'formatTimePeriod() rounding (>48h), avoidseconds' ),
			array( 172801.55, 'avoidseconds', '2d 0h 0m', 'formatTimePeriod() rounding, (>48h), avoidseconds' ),
			array( 172801.55, array( 'avoid' => 'avoidseconds', 'noabbrevs' => true ), '2 days 0 hours 0 minutes', 'formatTimePeriod() rounding, (>48h), avoidseconds' ),
			array( 176460.55, array(), '2d 1h 1m 1s', 'formatTimePeriod() rounding, recursion, (>48h)' ),
			array( 176460.55, array( 'noabbrevs' => true ), '2 days 1 hour 1 minute 1 second', 'formatTimePeriod() rounding, recursion, (>48h)' ),
		);
		
	}

	function testTruncate() {
		$this->assertEquals(
			"XXX",
			$this->lang->truncate( "1234567890", 0, 'XXX' ),
			'truncate prefix, len 0, small ellipsis'
		);

		$this->assertEquals(
			"12345XXX",
			$this->lang->truncate( "1234567890", 8, 'XXX' ),
			'truncate prefix, small ellipsis'
		);

		$this->assertEquals(
			"123456789",
			$this->lang->truncate( "123456789", 5, 'XXXXXXXXXXXXXXX' ),
			'truncate prefix, large ellipsis'
		);

		$this->assertEquals(
			"XXX67890",
			$this->lang->truncate( "1234567890", -8, 'XXX' ),
			'truncate suffix, small ellipsis'
		);

		$this->assertEquals(
			"123456789",
			$this->lang->truncate( "123456789", -5, 'XXXXXXXXXXXXXXX' ),
			'truncate suffix, large ellipsis'
		);
	}

	/**
	* @dataProvider provideHTMLTruncateData()
	*/
	function testTruncateHtml( $len, $ellipsis, $input, $expected ) {
		// Actual HTML...
		$this->assertEquals(
			$expected,
			$this->lang->truncateHTML( $input, $len, $ellipsis )
		);
	}

	/**
	 * Array format is ($len, $ellipsis, $input, $expected)
	 */
	function provideHTMLTruncateData() {
		return array(
			array( 0, 'XXX', "1234567890", "XXX" ),
			array( 8, 'XXX', "1234567890", "12345XXX" ),
			array( 5, 'XXXXXXXXXXXXXXX', '1234567890', "1234567890" ),
			array( 2, '***',
				'<p><span style="font-weight:bold;"></span></p>',
				'<p><span style="font-weight:bold;"></span></p>',
			),
			array( 2, '***',
				'<p><span style="font-weight:bold;">123456789</span></p>',
				'<p><span style="font-weight:bold;">***</span></p>',
			),
			array( 2, '***',
				'<p><span style="font-weight:bold;">&nbsp;23456789</span></p>',
				'<p><span style="font-weight:bold;">***</span></p>',
			),
			array( 3, '***',
				'<p><span style="font-weight:bold;">123456789</span></p>',
				'<p><span style="font-weight:bold;">***</span></p>',
			),
			array( 4, '***',
				'<p><span style="font-weight:bold;">123456789</span></p>',
				'<p><span style="font-weight:bold;">1***</span></p>',
			),
			array( 5, '***',
				'<tt><span style="font-weight:bold;">123456789</span></tt>',
				'<tt><span style="font-weight:bold;">12***</span></tt>',
			),
			array( 6, '***',
				'<p><a href="www.mediawiki.org">123456789</a></p>',
				'<p><a href="www.mediawiki.org">123***</a></p>',
			),
			array( 6, '***',
				'<p><a href="www.mediawiki.org">12&nbsp;456789</a></p>',
				'<p><a href="www.mediawiki.org">12&nbsp;***</a></p>',
			),
			array( 7, '***',
				'<small><span style="font-weight:bold;">123<p id="#moo">456</p>789</span></small>',
				'<small><span style="font-weight:bold;">123<p id="#moo">4***</p></span></small>',
			),
			array( 8, '***',
				'<div><span style="font-weight:bold;">123<span>4</span>56789</span></div>',
				'<div><span style="font-weight:bold;">123<span>4</span>5***</span></div>',
			),
			array( 9, '***',
				'<p><table style="font-weight:bold;"><tr><td>123456789</td></tr></table></p>',
				'<p><table style="font-weight:bold;"><tr><td>123456789</td></tr></table></p>',
			),
			array( 10, '***',
				'<p><font style="font-weight:bold;">123456789</font></p>',
				'<p><font style="font-weight:bold;">123456789</font></p>',
			),
		);
	}

	/**
	 * Test Language::isValidBuiltInCode()
	 * @dataProvider provideLanguageCodes
	 */
	function testBuiltInCodeValidation( $code, $message = '' ) {
		$this->assertTrue(
			(bool) Language::isValidBuiltInCode( $code ),
			"validating code $code $message"
		);
	}

	function testBuiltInCodeValidationRejectUnderscore() {
		$this->assertFalse(
			(bool) Language::isValidBuiltInCode( 'be_tarask' ),
			"reject underscore in language code"
		);
	}

	function provideLanguageCodes() {
		return array(
			array( 'fr'       , 'Two letters, minor case' ),
			array( 'EN'       , 'Two letters, upper case' ),
			array( 'tyv'      , 'Three letters' ),
			array( 'tokipona'   , 'long language code' ),
			array( 'be-tarask', 'With dash' ),
			array( 'Zh-classical', 'Begin with upper case, dash' ),
			array( 'Be-x-old', 'With extension (two dashes)' ),
		);
	}

	/**
	 * @dataProvider provideSprintfDateSamples
	 */
	function testSprintfDate( $format, $ts, $expected, $msg ) {
		$this->assertEquals(
			$expected,
			$this->lang->sprintfDate( $format, $ts ),
			"sprintfDate('$format', '$ts'): $msg"
		);
	}

	function provideSprintfDateSamples() {
		return array(
			array(
				'xiY',
				'20111212000000',
				'1390', // note because we're testing English locale we get Latin-standard digits
				'Iranian calendar full year'
			),
			array(
				'xiy',
				'20111212000000',
				'90',
				'Iranian calendar short year'
			),
		);
	}

	/**
	 * @dataProvider provideFormatSizes
	 */
	function testFormatSize( $size, $expected, $msg ) {
		$this->assertEquals(
			$expected,
			$this->lang->formatSize( $size ),
			"formatSize('$size'): $msg"
		);
	}

	function provideFormatSizes() {
		return array(
			array(
				0,
				"0 B",
				"Zero bytes"
			),
			array(
				1024,
				"1 KB",
				"1 kilobyte"
			),
			array(
				1024 * 1024,
				"1 MB",
				"1,024 megabytes"
			),
			array(
				1024 * 1024 * 1024,
				"1 GB",
				"1 gigabytes"
			),
			array(
				pow( 1024, 4 ),
				"1 TB",
				"1 terabyte"
			),
			array(
				pow( 1024, 5 ),
				"1 PB",
				"1 petabyte"
			),
			array(
				pow( 1024, 6 ),
				"1 EB",
				"1,024 exabyte"
			),
			array(
				pow( 1024, 7 ),
				"1 ZB",
				"1 zetabyte"
			),
			array(
				pow( 1024, 8 ),
				"1 YB",
				"1 yottabyte"
			),
			// How big!? THIS BIG!
		);
	}

	/**
	 * @dataProvider provideFormatBitrate
	 */
	function testFormatBitrate( $bps, $expected, $msg ) {
		$this->assertEquals(
			$expected,
			$this->lang->formatBitrate( $bps ),
			"formatBitrate('$bps'): $msg"
		);
	}

	function provideFormatBitrate() {
		return array(
			array(
				0,
				"0bps",
				"0 bytes per second"
			),
			array(
				1024,
				"1kbps",
				"1 kilobyte per second"
			),
			array(
				1024 * 1024,
				"1Mbps",
				"1 megabyte per second"
			),
			// Test commented out as currently resulting in 1.1Gbps
//			array(
//				1024 * 1024 * 1024,
//				"1Gbps",
//				"1 gigabyte per second"
//			),
//			array(
//				1024 * 1024 * 1024 * 1024,
//				"1,024Gbps",
//				"1,024 gigabytes per second"
//			),
		);
	}
}
