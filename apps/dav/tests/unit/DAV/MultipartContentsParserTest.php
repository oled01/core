<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

		$this->boundrary = '--boundrary';

	}

	public function testGetPartEmpty() {
		//TODO: this test is not passing, need to rethink that if it should return exception or just (null, null)
		$length = 0;
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStream($length);

		$this->assertEquals([null,null], $multipartContentsParser->getPart($this->boundrary));
	}

	public function testStreamRead0() {
		$length = 0;
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStream($length);

		$this->assertEquals($bodyString, $multipartContentsParser->streamRead($length));
	}

	public function testStreamReadBelow8192() {
		$length = 1000;
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStream($length);

		$this->assertEquals($bodyString, $multipartContentsParser->streamRead($length));
	}

	public function testStreamReadOf8192() {
		$length = 8192;
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStream($length);

		$this->assertEquals($bodyString, $multipartContentsParser->streamRead($length));
	}

	public function testStreamReadAbove8192() {
		$length = 20000;
		list($multipartContentsParser, $bodyString) = $this->fillMultipartContentsParserStream($length);

		$this->assertEquals($bodyString, $multipartContentsParser->streamRead($length));
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
			->method('getBody');

		$mcp = new \OCA\DAV\Files\MultipartContentsParser($request);
		//give negative length
		$mcp->streamRead(-1);
	}

	/**
	 * It will access the getContents, but getBody will signal error returning false
	 *
	 * @expectedException \Sabre\DAV\Exception\BadRequest
	 * @expectedExceptionMessage Unable to get request content
	 */
	public function testStreamReadWithGetBodyError() {
		$request = $this->getMockBuilder('Sabre\HTTP\RequestInterface')
			->disableOriginalConstructor()
			->getMock();
		$request->expects($this->once())
			->method('getBody')
			->will($this->returnValue(false));

		$length = 10000;
		$mcp = new \OCA\DAV\Files\MultipartContentsParser($request);

		$mcp->streamRead($length);
	}


	private function fillMultipartContentsParserStream($length){
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
}
