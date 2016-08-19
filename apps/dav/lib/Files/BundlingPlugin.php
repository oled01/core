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

use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\BadRequest;
use OC\Files\View;
use Sabre\HTTP\URLUtil;
use OCP\Lock\ILockingProvider;
use OC\Files\FileInfo;


/**
 * This plugin is responsible for interconnecting three components of the OC server:
 * - RequestInterface object handler for request incoming from the client
 * - MultiparContentsParser responsible for reading the contents of the request body
 * - BundledFile responsible for storage of the file associated with request in the OC server
 *
 * Bundling plugin is responsible for receiving, validation and processing of the multipart/related request containing files.
 *
 */
class BundlingPlugin extends ServerPlugin {

	/**
	 * Reference to main server object
	 *
	 * @var \Sabre\DAV\Server
	 */
	private $server;

	/**
	 * @var \Sabre\HTTP\RequestInterface
	 */
	private $request;

	/**
	 * @var \Sabre\HTTP\ResponseInterface
	 */
	private $response;

	/**
	 * @var String
	 */
	private $boundary = null;

	/**
	 * @var String
	 */
	private $startContent = null;
	/**
	 * @var \OCA\DAV\FilesBundle
	 */
	private $contentHandler = null;

	/**
	 * @var Bool
	 */
	private $endDelimiterReached = false;

	/**
	 * @var String
	 */
	private $userFilesHome = null;

	/**
	 * @var View
	 */
	private $fileView;

	/**
	 * @var Array
	 */
	private $cacheValidParents = null;
	
	/**
	 * Plugin contructor
	 */
	public function __construct(View $view) {
		$this->fileView = $view;
	}

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by \Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the requires event subscriptions.
	 *
	 * @param \Sabre\DAV\Server $server
	 * @return void
	 */
	public function initialize(\Sabre\DAV\Server $server) {

		$this->server = $server;

		$server->on('method:POST', array($this, 'handleBundledUpload'));
	}

	/**
	 * We intercept this to handle method:POST on a dav resource and process the bundled files multipart HTTP request.
	 *
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 *
	 * @throws /Sabre\DAV\Exception\BadRequest
	 * @throws /Sabre\DAV\Exception\Forbidden
	 * @return null|false
	 */
	public function handleBundledUpload(RequestInterface $request, ResponseInterface $response) {
		$this->request = $request;
		$this->response = $response;

		//TODO: add emit (beforeBind)


		//validate the request before parsing
		$this->validateRequest();

		//TODO: enshour to sign in proper classes to this emit
		if (!$this->server->emit('beforeWriteBundle', [$this->userFilesHome])){
			throw new Forbidden('beforeWriteBundle preconditions failed');
		}
		
		//Create objects ($bundleMetadata,$bundleBinaries) from metadata and binary contents
		list($bundleMetadata, $bundleBinaries) = $this->getBundleContents();

		//Process bundle and send a multistatus response
		$result = $this->processBundle($bundleMetadata,$bundleBinaries);

		//TODO: add emit (afterBind)
		//TODO: add emit (afterCreateFile)
		return $result;
	}

	/**
	 * Adds to multistatus response exception class string and exception message for specific file
	 *
	 * @return void
	 */
	protected function handleFileMultiStatusError(&$bundleResponseProperties,$href,$status,$propertyException, $propertyMessage){
		$entry['href'] = $href;
		$entry[$status]['{DAV:}error']['{http://sabredav.org/ns}exception'] = $propertyException;
		$entry[$status]['{DAV:}error']['{http://sabredav.org/ns}message'] = $propertyMessage;
		$bundleResponseProperties[] = $entry;
	}

	/**
	 * TODO: description and variables
	 *
	 * @return void
	 */
	protected function handleFileMultiStatus(&$bundleResponseProperties,$href, $status,$properties){
		$entry['href'] = $href;
		$entry[$status] = $properties;
		$bundleResponseProperties[] = $entry;
	}

	/**
	 * Get a part of request.
	 *
	 * @param  RequestInterface $request
	 * @param  String $boundary
	 * @throws TODO: handle exception
	 * @return array
	 */
	protected function getPart(RequestInterface $request, $boundary) {
		if ($this->contentHandler === null) {
			$contentHandler = new MultipartContentsParser($request);
		} else{
			$contentHandler = $this->contentHandler;
		}
		$result = $contentHandler->getPart($boundary);
		$this->endDelimiterReached = $contentHandler->getEndDelimiterReached();
		return $result;
	}

	/**
	 * Check multipart headers.
	 *
	 * @throws /Sabre\DAV\Exception\BadRequest
	 * @throws /Sabre\DAV\Exception\Forbidden
	 * @return void
	 */
	private function validateRequest() {
		// Making sure the end node exists
		//TODO: add support for user creation if that is first sync. Currently user has to be created.
		$this->userFilesHome = $this->request->getPath();
		$userFilesHomeNode = $this->server->tree->getNodeForPath($this->userFilesHome);
		if (!($userFilesHomeNode instanceof FilesHome)){
			throw new BadRequest('URL endpoint has to be instance of \OCA\DAV\Files\FilesHome');
		}

		//TODO: validate if it has required headers
		$headers = array('Content-Type');
		foreach ($headers as $header) {
			$value = $this->request->getHeader($header);
			if ($value === null) {
				//TODO:HANDLE EXCEPTION
				throw new BadRequest(sprintf('%s header is needed', $header));
			} elseif (!is_int($value) && empty($value)) {
				//TODO:HANDLE EXCEPTION
				throw new BadRequest(sprintf('%s header must not be empty', $header));
			}
		}

		$contentParts = explode(';', $this->request->getHeader('Content-Type'));
		if (count($contentParts) != 3) {
			//TODO:handle exception
			throw new Forbidden('Improper Content-type format. Boundary may be missing');
		}
		
		//Validate content-type
		//TODO: add suport for validation of more fields than only Content-Type, if needed
		$contentType = trim($contentParts[0]);
		$expectedContentType = 'multipart/related';
		if ($contentType != $expectedContentType) {
			//TODO: handle exception
			throw new BadRequest(sprintf(
				'Content-Type must be %s',
				$expectedContentType
			));
		}

		//Validate boundrary
		$boundaryPart = trim($contentParts[1]);
		$shouldStart = 'boundary=';
		if (substr($boundaryPart, 0, strlen($shouldStart)) != $shouldStart) {
			//TODO:handle exception
			throw new BadRequest('Boundary is not set');
		}

		$boundary = substr($boundaryPart, strlen($shouldStart));
		if (substr($boundary, 0, 1) == '"' && substr($boundary, -1) == '"') {
			$boundary = substr($boundary, 1, -1);
		}

		//Validate start
		$startPart = trim($contentParts[2]);
		$shouldStart = 'start=';
		if (substr($startPart, 0, strlen($shouldStart)) != $shouldStart) {
			//TODO:handle exception
			throw new BadRequest('Boundary is not set');
		}

		$start = substr($startPart, strlen($shouldStart));
		if (substr($start, 0, 1) == '"' && substr($start, -1) == '"') {
			$start = substr($start, 1, -1);
		}

		$this->startContent = $start;
		$this->boundary = $boundary;
	}

	/**
	 * Get the bundle metadata from the request.
	 *
	 * Note: MUST be called before getBundleContents, and just one time.
	 *
	 * @throws /Sabre\DAV\Exception\BadRequest
	 * @return array
	 */
	private function getBundleMetadata($metadataContent, $metadataContentHeader) {
		if (!isset($metadataContentHeader['content-type'])) {
			//TODO: handle exception PROPERLY
			throw new BadRequest('Metadata does not contain content-type header');
		}
		$expectedContentType = 'application/json';
		if (substr($metadataContentHeader['content-type'], 0, strlen($expectedContentType)) != $expectedContentType) {
			//TODO: handle exception PROPERLY
			throw new BadRequest(sprintf(
				'Expected content type of first part is %s. Found %s',
				$expectedContentType,
				$metadataContentHeader['content-type']
			));
		}
		
		//rewind to the begining of file for streamCopy and copy stream
		rewind($metadataContent);
		$jsonContent = json_decode(stream_get_contents($metadataContent), true);
		if ($jsonContent === null) {
			//TODO: handle exception PROPERLY
			throw new BadRequest('Unable to parse JSON');
		}
		fclose($metadataContent);

		return $jsonContent;
	}

	/**
	 * Get the content parts of the request.
	 *
	 * Note: MUST be called after getBundleMetadata, and just one time.
	 *
	 * @throws /Sabre\DAV\Exception\BadRequest
	 * @return array ( $resource )
	 */
	private function getBundleContents() {
		$bundleBinaries = null;
		while(!$this->endDelimiterReached){
			list($partHeader, $partContent) = $this->getPart($this->request, $this->boundary);

			if (!isset($partHeader['content-id']) || is_null($partContent)){
				// Binary contents are not aware of what file they belog to, so ignore content part without content-id.
				// Lack of corresponding $bundleBinaries[$binaryID] binary part will trigger an BundledFile class error for the corresponding content-id in metadata
				continue;
			}
			$binaryID = $partHeader['content-id'];

			//perform shallow copying of binary content to an array identified by content-id
			$bundleBinaries[$binaryID]=$partContent;
			$contentMetadata[$binaryID]=$partHeader;
		}

		if (!isset($contentMetadata[$this->startContent]) || !isset($bundleBinaries[$this->startContent])){
			throw new BadRequest('Bundle object has no associated metadata');
		}
		$bundleMetadata = $this->getBundleMetadata($bundleBinaries[$this->startContent], $contentMetadata[$this->startContent]);

		return array($bundleMetadata, $bundleBinaries);
	}

	/**
	 * Process multipart contents and send appropriete response
	 *
	 * @return boolean
	 */
	private function processBundle($bundleMetadata, $bundleBinaries) {
		$bundleResponseProperties = array();

		//Loop reads through each fileMetadata included in the Bundle Metadata JSON
		foreach($bundleMetadata as $filePath => $fileAttributes)
		{
			list($folderPath, $fileName) = URLUtil::splitPath($filePath);

			if ($folderPath === ''){
				$fullFolderPath = $this->userFilesHome;
			}
			else{
				$fullFolderPath = $this->userFilesHome . '/' . $folderPath;
			}

			if (!isset($this->cacheValidParents[$folderPath])){
				$this->cacheValidParents[$folderPath] = ($this->server->tree->nodeExists($fullFolderPath) && $this->fileView->isCreatable($folderPath));
			}

			if (!$this->cacheValidParents[$folderPath]){
				$this->handleFileMultiStatusError($bundleResponseProperties, $filePath, 400,'Sabre\DAV\Exception\BadRequest','File creation on not existing or without creation permission parent folder is not permitted');
				continue;
			}

			//Check if file metadata specifies which binaries belong to the file
			if (!isset($fileAttributes['content-id'])){
				$this->handleFileMultiStatusError($bundleResponseProperties, $filePath, 400,'Sabre\DAV\Exception\BadRequest','Request contains part without required headers and multistatus response cannot be constructed');
				continue;
			}
			$fileContentID = $fileAttributes['content-id'];

			//This part should check whether binary content exists for specified content-ids in metadata for that file.
			if (!isset($bundleBinaries[$fileContentID])){
				$this->handleFileMultiStatusError($bundleResponseProperties, $filePath, 400,'Sabre\DAV\Exception\BadRequest','File object has no associated data binaries, wrong metadata or corrupted file contents');
				continue;
			}

			//that assembles the file
			$fileData = $bundleBinaries[$fileContentID];

			try {
				$absoluteFilePath = $this->fileView->getAbsolutePath($folderPath) . '/' . $fileName;

				// using a dummy FileInfo is acceptable here since it will be refreshed after the put is complete
				$info = new FileInfo($absoluteFilePath, null, null, array(), null);
				$node = new BundledFile($this->fileView, $info);
				$node->acquireLock(ILockingProvider::LOCK_SHARED);
				
				//file put return properties for bundle response, it is dependent on class BundledFile
				$properties = $node->createFile($fileData, $fileAttributes);
			}
			catch(\Exception $e){
				$this->handleFileMultiStatusError($bundleResponseProperties, $filePath, 400, 'Sabre\DAV\Exception\BadRequest', $e->getMessage());
				continue;
			}

			$this->server->tree->markDirty($filePath);
			$this->handleFileMultiStatus($bundleResponseProperties, $filePath, 200, $properties);
		}
		//multistatus response anounced
		$this->response->setHeader('Content-Type', 'application/xml; charset=utf-8');
		$this->response->setStatus(207);
		$data = $this->server->generateMultiStatus($bundleResponseProperties);
		$this->response->setBody($data);

		return false;
	}
}