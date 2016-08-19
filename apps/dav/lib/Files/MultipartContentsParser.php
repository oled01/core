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
namespace OCA\DAV\Files;

use Sabre\HTTP\RequestInterface;
use Sabre\DAV\Exception\BadRequest;

/**
 * This class is used to parse multipart/related HTTP message according to RFC http://www.rfc-archive.org/getrfc.php?rfc=2387
 * This class requires a message to contain Content-length parameters, which is used in high performance reading of file contents.
 */

class MultipartContentsParser {
    /**
     * @var \Sabre\HTTP\RequestInterface
     */
    private $request;

    /**
     * @var integer
     */
    private $cursor;

    /**
     * @var resource
     */
    private $content = null;

    /**
     * @var Bool
     */
    private $endDelimiterReached = false;

    /**
     * Constructor.
     *
     * @param \Sabre\HTTP\RequestInterface $request
     */
    public function __construct(RequestInterface $request) {
        $this->request = $request;
        $this->cursor = 0;
    }

    /**
     * Get a line.
     *
     * If false is return, it's the end of file.
     *
     * @throws \Sabre\DAV\Exception\BadRequest
     * @return string|boolean
     */
    public function gets() {
        $content = $this->getContent();
        if (!is_resource($content)) {
            throw new BadRequest('Unable to get request content');
        }
        
        $line = fgets($content);
        $this->cursor = ftell($content);


        return $line;
    }

    /**
     * @return int
     */
    public function getCursor() {
        return $this->cursor;
    }

    /**
     * @return int
     */
    public function getEndDelimiterReached() {
        return $this->endDelimiterReached;
    }

    /**
     * Return if end of file.
     *
     * @return bool
     */
    public function eof() {
        return $this->cursor == -1 || (is_resource($this->getContent()) && feof($this->getContent()));
    }

    /**
     * Get request content.
     *
     * @return resource
     * @throws \Sabre\DAV\Exception\BadRequest
     */
    public function getContent() {
        if ($this->content === null) {
            $this->content = $this->request->getBody();

            if (!$this->content) {
                throw new BadRequest('Unable to get request content');
            }
        }

        return $this->content;
    }

    /**
     * Get a part of request separated by boundrary $boundary.
     *
     * @param  String $boundary
     *
     * @throws \Sabre\DAV\Exception\BadRequest
     * @return array (array $headers, resource $bodyStream)
     */
    public function getPart($boundary) {
        $delimiter = '--'.$boundary."\r\n";
        $endDelimiter = '--'.$boundary.'--';
        $boundaryCount = 0;
        $content = '';
        while (!$this->eof()) {
            $line = $this->gets();
            if ($line === false) {
                throw new BadRequest('An error appears while reading input in content part');
            }

            if ($boundaryCount == 0) {
                if ($line != $delimiter) {
                    if ($this->getCursor() == strlen($line)) {
                        throw new BadRequest('Expected boundary delimiter in content part');
                    }
                } else {
                    continue;
                }
                //at this point we know, that first line was boundrary
                $boundaryCount++;
            }
            elseif ($boundaryCount == 1 && $line == "\r\n"){
                //header-end according to RFC
                $content .= $line;
                $headers = $this->readHeaders($content);

                if (!isset($headers['content-length'])){
                    throw new BadRequest('Content-length header in one of the contents is missing, multipart message cannot be parsed');
                }
                $bodyLengthHeader = intval($headers['content-length']);
                $bodyString = $this->streamRead($bodyLengthHeader);

                //TODO: WARNING, this function uses php://temp, which my cause some issues with temp location
                $bodyStream = fopen('php://temp', 'r+');
                $bodyStreamLength = fwrite($bodyStream, $bodyString);

                if ($bodyStreamLength != $bodyLengthHeader){
                    $this->resetContent($headers,$bodyStream,$bodyStream);
                }

                // TODO: Discuss if it has to be option or required field
			    if (isset($headers['content-md5'])) {
                    //check if the expected file is corrupted
                    $hash = md5($bodyString);
                    $contentMD5 = $headers['content-md5'];
                    if (!($hash === $contentMD5)) {
                        // Binary contents are not aware of what file they belog to, so ignore content part with wrong md5 sum.
                        // null as file header will trigger an BundledFile class error for the corresponding content-id in metadata
                        $this->resetContent($headers,$bodyStream,$bodyStream);
                    }
                }

                unset($bodyString);
                $content = null;
                $boundaryCount++;
                
                //at this point we expect boundrary
                continue;
            }
            elseif ($boundaryCount == 2 && $line == $delimiter) {
                break;
            }
            elseif ($boundaryCount == 2 && ($line == $endDelimiter || $line == $endDelimiter."\r\n")) {
                $this->endDelimiterReached = true;
                break;
            }

            $content .= $line;
        }

        return array($headers, $bodyStream);
    }

    /**
     * Resets the header and body, as well as associated bodyStream
     * 
     * @param  array reference $headers
     * @param  resource reference $bodyStream
     * 
     * @return void
     */
    private function resetContent(&$headers,&$bodyStream) {
        $headers = null;
        fclose($bodyStream);
        $bodyStream = null;
    }
    
    /**
     * Read the contents from the current file pointer to the specified length
     *
     * @param int $length
     * 
     * @throws \Sabre\DAV\Exception\BadRequest
     * @return string $buf
     */
    public function streamRead($length) {
        if ($length<0) {
            throw new BadRequest('streamRead cannot read contents with negative length');
        }
        $source = $this->getContent();
        $bufChunkSize = 8192;
        $count = $length;
        $buf = '';

        while ($count!=0) {
            $bufSize = (($count - $bufChunkSize)<0) ? $count : $bufChunkSize;
            $buf .= fread($source, $bufSize);
            $count -= $bufSize;
        }
        //warning: mind that one needs rewind to get to the begining of the files. $stream has a file pointer at the end.
        return $buf;
    }

    /**
     * Get headers from content
     *
     * @param string $content
     * 
     * @throws \Sabre\DAV\Exception\BadRequest
     * @return Array $headers
     */
    private function readHeaders($content) {
        $headerLimitation = strpos($content, "\r\n\r\n");
        if ($headerLimitation === false) {
            //TODO: handle exception
            throw new BadRequest('Unable to determine headers limit for content part');
        }
        $headersContent = substr($content, 0, $headerLimitation);
        $headersContent = trim($headersContent);
        foreach (explode("\r\n", $headersContent) as $header) {
            $parts = explode(':', $header);
            if (count($parts) != 2) {
                //TODO: handle exception
                throw new BadRequest('Header of content part contains incorrect headers');
            }
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        return $headers;
    }
}