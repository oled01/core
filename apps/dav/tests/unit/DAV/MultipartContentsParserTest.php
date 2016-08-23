<?php
/**
 * @author Piotr Mrowczynski <Piotr.Mrowczynski@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\DAV\Tests\unit\DAV;

use Test\TestCase;

class MultipartContentsParserTest extends TestCase {

	private $boundrary;

	protected function setUp() {
		parent::setUp();

		$this->boundrary = 'boundary';

	}

	/**
	 * getpart function without content-length header in one of contents parts should abort whole bundle, since whole multipart cannot be parsed
	 *
	 * @expectedException \Sabre\DAV\Exception\BadRequest
	 * @expectedExceptionMessage Content-length header in one of the contents is missing, multipart message cannot be parsed
	 */
	public function testGetPartThrowContentLength() {
		$bodyFull = "--boundary\r\nContent-ID: 0\r\n\r\nblabla\r\n--boundary--";
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody($bodyFull);
		$multipartContentsParser->getPart($this->boundrary);
	}

	/**
	 * If one one the content parts does not contain boundrary, means that received wrong request
	 *
	 * @expectedException \Sabre\DAV\Exception\BadRequest
	 * @expectedExceptionMessage Expected boundary delimiter in content part
	 */
	public function testGetPartThrowNoBoundraryFound() {
		// Calling multipletimes getPart on parts without contents should return null,null and signal immedietaly that endDelimiter was reached
		$bodyFull = "--boundary_wrong\r\n--boundary--";
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody($bodyFull);
		$multipartContentsParser->getPart($this->boundrary);
	}

	/**
	 * streamRead function with incorrect parameter
	 *
	 * @expectedException \Sabre\DAV\Exception\BadRequest
	 * @expectedExceptionMessage Method streamRead cannot read contents with negative length
	 */
	public function testGetPartThrowHeaderLimitation() {
		$request = $this->getMockBuilder('Sabre\HTTP\RequestInterface')
			->disableOriginalConstructor()
			->getMock();
		$request->expects($this->never())
			->method('getBody');

		$mcp = new \OCA\DAV\Files\MultipartContentsParser($request);
		//give negative length
		$mcp->streamRead(-1);
	}

	/**
	 * It should not access method getContents, which will aslk request for body
	 *
	 * @expectedException \Sabre\DAV\Exception\BadRequest
	 * @expectedExceptionMessage streamRead cannot read contents with negative length
	 */
	public function testStreamReadThrowIncorrectLength() {
		$request = $this->getMockBuilder('Sabre\HTTP\RequestInterface')
			->disableOriginalConstructor()
			->getMock();
		$request->expects($this->never())
			->method('getBody')
			->will($this->returnValue(false));

		$mcp = new \OCA\DAV\Files\MultipartContentsParser($request);
		//give negative length
		$mcp->streamRead(-1);
	}

	public function testGetPartWrongBoundaryCases() {
		// Calling multipletimes getPart on parts without contents should return null,null and signal immedietaly that endDelimiter was reached
		$bodyFull = "--boundary\r\n--boundary_wrong\r\n--boundary--";
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody($bodyFull);
		$this->assertEquals([null,null],$multipartContentsParser->getPart($this->boundrary));
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());
	}

	public function testGetPartSkipWrongMD5() {
		$bodyContent = 'blabla';
		$contentMD5 = md5($bodyContent);
		$bodyFull = '--boundary'
			."\r\nContent-ID: 0\r\nContent-MD5: WRONG_MD5\r\nContent-Type: application/json; charset=UTF-8\r\nContent-length: 0\r\n\r\n"
			."\r\n--boundary\r\nContent-ID: 1\r\nContent-MD5: $contentMD5\r\nContent-Type: application/json; charset=UTF-8\r\nContent-length: 6\r\n\r\n"
			."$bodyContent\r\n--boundary--";
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody($bodyFull);
		$this->assertEquals([null,null], $multipartContentsParser->getPart($this->boundrary));

		$headers['content-length'] = '6';
		$headers['content-type'] = 'application/json; charset=UTF-8';
		$headers['content-id'] = '1';
		$headers['content-md5'] = $contentMD5;
		list($headersParsed, $bodyParsed) = $multipartContentsParser->getPart($this->boundrary);
		rewind($bodyParsed);
		$bodyParsedString = stream_get_contents($bodyParsed);
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());
		$this->assertEquals($bodyContent, $bodyParsedString);
		$this->assertEquals($headers, $headersParsed);
	}

	public function testGetPartContents() {
		// Test empty content
		$bodyFull = "--boundary\r\n";
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody($bodyFull);
		$this->assertEquals([null,null], $multipartContentsParser->getPart($this->boundrary));
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());

		// Test empty content
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody('');
		$this->assertEquals([null,null], $multipartContentsParser->getPart($this->boundrary));
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());

		// Calling multipletimes getPart on parts without contents should return null,null and signal immedietaly that endDelimiter was reached
		// endDelimiter should be signaled after first getPart since it will read --boundrary till it finds contents.
		$bodyFull = "--boundary\r\n--boundary\r\n--boundary--";
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody($bodyFull);
		$this->assertEquals([null,null],$multipartContentsParser->getPart($this->boundrary));
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());
		$this->assertEquals([null,null],$multipartContentsParser->getPart($this->boundrary));
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());
		$this->assertEquals([null,null],$multipartContentsParser->getPart($this->boundrary));
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());
		$this->assertEquals([null,null],$multipartContentsParser->getPart($this->boundrary));
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());

		// Test First part with content and second without content returning null-null
		// The behaviour is motivated by the fact that if there is noting between start content boundrary and the end of multipart boundrary,
		// it should not raise and error, but simply skip contents returning null null and setting endDelimiterReached to true.
		$bodyContent = 'blabla';
		$bodyFull = '--boundary'
			."\r\nContent-ID: 0\r\nContent-Type: application/json; charset=UTF-8\r\nContent-length: 6\r\n\r\n"
			."$bodyContent\r\n--boundary\r\n--boundary--";
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody($bodyFull);
		$headers['content-length'] = '6';
		$headers['content-type'] = 'application/json; charset=UTF-8';
		$headers['content-id'] = '0';
		list($headersParsed, $bodyParsed) = $multipartContentsParser->getPart($this->boundrary);
		$this->assertEquals(false,$multipartContentsParser->getEndDelimiterReached());
		rewind($bodyParsed);
		$bodyParsedString = stream_get_contents($bodyParsed);
		$this->assertEquals($bodyContent, $bodyParsedString);
		$this->assertEquals($headers, $headersParsed);
		$this->assertEquals([null,null],$multipartContentsParser->getPart($this->boundrary));
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());

		// Test First part without content and second with content, expects that it will just skip the empty boundrary and read the next contents within the same run of getPart
		// The behaviour is motivated by the fact that iterator at the first boundrary occurence expects next line to be contents and it will iterate till it finds it.
		// It should set endDelimiterReached to true immedietaly
		$bodyContent = 'blabla';
		$bodyFull = '--boundary'
			."\r\n--boundary\r\nContent-ID: 0\r\nContent-Type: application/json; charset=UTF-8\r\nContent-length: 6\r\n\r\n"
			."$bodyContent\r\n--boundary--";
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody($bodyFull);
		$headers['content-length'] = '6';
		$headers['content-type'] = 'application/json; charset=UTF-8';
		$headers['content-id'] = '0';
		list($headersParsed, $bodyParsed) = $multipartContentsParser->getPart($this->boundrary);
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());
		rewind($bodyParsed);
		$bodyParsedString = stream_get_contents($bodyParsed);
		$this->assertEquals($bodyContent, $bodyParsedString);
		$this->assertEquals($headers, $headersParsed);

		// Test First part without content and second with content, expects that it will return first empty string and next will be content
		$bodyContent = 'blabla';
		$bodyFull = '--boundary'
			."\r\nContent-ID: 0\r\nContent-Type: application/json; charset=UTF-8\r\nContent-length: 0\r\n\r\n"
			."\r\n--boundary\r\nContent-ID: 1\r\nContent-Type: application/json; charset=UTF-8\r\nContent-length: 6\r\n\r\n"
			."$bodyContent\r\n--boundary--";
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithBody($bodyFull);
		$headers['content-length'] = '0';
		$headers['content-type'] = 'application/json; charset=UTF-8';
		$headers['content-id'] = '0';
		list($headersParsed, $bodyParsed) = $multipartContentsParser->getPart($this->boundrary);
		$this->assertEquals(false,$multipartContentsParser->getEndDelimiterReached());
		rewind($bodyParsed);
		$bodyParsedString = stream_get_contents($bodyParsed);
		$this->assertEquals("", $bodyParsedString);
		$this->assertEquals($headers, $headersParsed);
		$headers['content-length'] = '6';
		$headers['content-type'] = 'application/json; charset=UTF-8';
		$headers['content-id'] = '1';
		list($headersParsed, $bodyParsed) = $multipartContentsParser->getPart($this->boundrary);
		$this->assertEquals(true,$multipartContentsParser->getEndDelimiterReached());
		rewind($bodyParsed);
		$bodyParsedString = stream_get_contents($bodyParsed);
		$this->assertEquals($bodyContent, $bodyParsedString);
		$this->assertEquals($headers, $headersParsed);
	}

	public function testStreamRead() {
		$length = 0;
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithChars($length);
		$this->assertEquals($bodyString, $multipartContentsParser->streamRead($length));

		$length = 1000;
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithChars($length);
		$this->assertEquals($bodyString, $multipartContentsParser->streamRead($length));

		$length = 8192;
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithChars($length);
		$this->assertEquals($bodyString, $multipartContentsParser->streamRead($length));

		$length = 20000;
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStreamWithChars($length);
		$this->assertEquals($bodyString, $multipartContentsParser->streamRead($length));
	}

	private function fillMultipartContentsParserStreamWithChars($length){
		$bodyStream = fopen('php://temp', 'r+');
		$bodyString = '';
		for ($x = 0; $x < $length; $x++) {
			$bodyString .= 'k';
		}
		fwrite($bodyStream, $bodyString);
		rewind($bodyStream);
		$request = $this->getMockBuilder('Sabre\HTTP\RequestInterface')
			->disableOriginalConstructor()
			->getMock();
		$request->expects($this->any())
			->method('getBody')
			->willReturn($bodyStream);

		$mcp = new \OCA\DAV\Files\MultipartContentsParser($request);
		return array($mcp, $bodyString);
	}

	private function fillMultipartContentsParserStreamWithBody($bodyString){
		$bodyStream = fopen('php://temp', 'r+');
		fwrite($bodyStream, $bodyString);
		rewind($bodyStream);
		$request = $this->getMockBuilder('Sabre\HTTP\RequestInterface')
			->disableOriginalConstructor()
			->getMock();
		$request->expects($this->any())
			->method('getBody')
			->willReturn($bodyStream);

		$mcp = new \OCA\DAV\Files\MultipartContentsParser($request);
		return array($mcp, $bodyString);
	}
}
