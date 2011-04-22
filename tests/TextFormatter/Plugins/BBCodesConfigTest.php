<?php

namespace s9e\Toolkit\Tests\TextFormatter\Plugins;

use s9e\Toolkit\Tests\Test;

include_once __DIR__ . '/../../Test.php';

/**
* @covers s9e\Toolkit\TextFormatter\Plugins\BBCodesConfig
*/
class BBCodesConfigTest extends Test
{
	/**
	* @test
	*/
	public function A_single_asterisk_is_accepted_as_a_BBCode_name()
	{
		$this->assertTrue($this->cb->BBCodes->isValidBBCodeName('*'));
	}

	/**
	* @test
	*/
	public function An_asterisk_followed_by_anything_is_rejected_as_a_BBCode_name()
	{
		$this->assertFalse($this->cb->BBCodes->isValidBBCodeName('**'));
		$this->assertFalse($this->cb->BBCodes->isValidBBCodeName('*b'));
	}

	/**
	* @test
	*/
	public function BBCode_names_can_start_with_a_letter()
	{
		$this->assertTrue($this->cb->BBCodes->isValidBBCodeName('a'));
	}

	/**
	* @test
	*/
	public function BBCode_names_cannot_start_with_anything_else()
	{
		$allowedChars    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz*';
		$disallowedChars = count_chars($allowedChars, 4);

		foreach (str_split($disallowedChars, 1) as $c)
		{
			$this->assertFalse($this->cb->BBCodes->isValidBBCodeName($c));
		}
	}

	/**
	* @test
	*/
	public function BBCode_names_can_only_contain_letters_numbers_and_underscores()
	{
		$allowedChars    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';
		$disallowedChars = count_chars($allowedChars, 4);

		foreach (str_split($disallowedChars, 1) as $c)
		{
			$this->assertFalse($this->cb->BBCodes->isValidBBCodeName('A' . $c));
		}
	}

	/**
	* @test
	* @expectedException InvalidArgumentException
	* @expectedExceptionMessage Invalid BBCode name ']'
	*/
	public function addBBCode_rejects_invalid_BBCode_names()
	{
		$this->cb->BBCodes->addBBCode(']');
	}

	/**
	* @test
	* @expectedException InvalidArgumentException
	* @expectedExceptionMessage BBCode 'A' already exists
	*/
	public function addBBCode_throws_an_exception_if_the_BBCode_name_is_already_in_use()
	{
		$this->cb->BBCodes->addBBCode('A');
		$this->cb->BBCodes->addBBCode('A');
	}

	public function testBbcodesAreMappedToATagOfTheSameNameByDefault()
	{
		$this->cb->BBCodes->addBBCode('B');

		$parserConfig = $this->cb->getParserConfig();

		$this->assertArrayHasKey('B', $parserConfig['tags']);
		$this->assertSame(
			'B', $parserConfig['plugins']['BBCodes']['bbcodesConfig']['B']['tagName']
		);
	}
}