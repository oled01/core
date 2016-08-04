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

use OCA\DAV\Connector\Sabre\Exception\EntityTooLarge;
use OCA\DAV\Connector\Sabre\Exception\FileLocked;
use OCA\DAV\Connector\Sabre\Exception\Forbidden as DAVForbiddenException;
use OCA\DAV\Connector\Sabre\Exception\UnsupportedMediaType;
use OCP\Files\ForbiddenException;
use OCP\Files\StorageNotAvailableException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Sabre\DAV\Exception;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\ServiceUnavailable;
use OCA\DAV\Connector\Sabre\File;

class BundledFile extends File {

	/**
	 * Creates the data
	 *
	 * The data argument is a an array of readable stream resources created by php://temp
	 *
	 * @param array ( resource ) $bundleContents
	 * @param array $metadata
	 *
	 * @throws Forbidden
	 * @throws UnsupportedMediaType
	 * @throws BadRequest
	 * @throws Exception
	 * @throws EntityTooLarge
	 * @throws ServiceUnavailable
	 * @throws FileLocked
	 * @return Array $property
	 */
	public function createFile($bundleContents, $metadata) {
		if (!isset($bundleContents) || !isset($metadata)){
			throw new Forbidden('Function received wrong argument');
		}

		if (!isset($metadata['content-id'])){
			throw new BadRequest('Request contains part without required headers and multistatus response cannot be constructed');
		}
		$fileContentID = $metadata['content-id'];

		//This part should check whether binary content exists for specified content-ids in metadata for that file.
		if (!isset($bundleContents[$fileContentID])){
			throw new BadRequest('File object has no associated data binaries, wrong metadata or corrupted file contents');
		}

		//that assembles the file
		$fileData = $bundleContents[$fileContentID];

		try {
			$exists = $this->fileView->file_exists($this->path);
			if ($this->info && $exists) {
				throw new Forbidden('File does exists, cannot create file');
			}
		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable("Storage not available exception " . $e->getMessage());
		}

		// verify path of the target
		$this->verifyPath();

		list($partStorage) = $this->fileView->resolvePath($this->path);
		$needsPartFile = $this->needsPartFile($partStorage) && (strlen($this->path) > 1);

		if ($needsPartFile) {
			// mark file as partial while uploading (ignored by the scanner)
			$partFilePath = $this->getPartFileBasePath($this->path) . '.ocTransferId' . rand() . '.part';
		} else {
			// upload file directly as the final path
			$partFilePath = $this->path;
		}

		// the part file and target file might be on a different storage in case of a single file storage (e.g. single file share)
		/** @var \OC\Files\Storage\Storage $partStorage */
		list($partStorage, $internalPartPath) = $this->fileView->resolvePath($partFilePath);
		/** @var \OC\Files\Storage\Storage $storage */
		list($storage, $internalPath) = $this->fileView->resolvePath($this->path);
		try {
			$target = $partStorage->fopen($internalPartPath, 'wb');
			if ($target === false) {
				// because we have no clue about the cause we can only throw back a 500/Internal Server Error
				\OCP\Util::writeLog('webdav', '\OC\Files\Filesystem::fopen() failed', \OCP\Util::ERROR);
				throw new Exception('Could not write file contents');
			}

			//will get the current pointer of written data. Should be at the end representing length of the stream
			$streamLength = ftell($fileData);

			//rewind to the begining of file for streamCopy and copy stream
			//you dont need to close the $stream since other files might use the stream resource. 
			rewind($fileData);
			list($count, $result) = \OC_Helper::streamCopy($fileData, $target);

			if ($result === false) {
				throw new Exception('Error while copying file to target location (copied bytes: ' . $count . 'expected filesize ' . $streamLength . ')');
			}

			// if content length is sent by client:
			// double check if the file was fully received
			// compare expected and actual size
			if ($streamLength != $count) {
				throw new BadRequest('expected filesize ' . $streamLength . ' got ' . $count);
			}

		} catch (\Exception $e) {
			if ($needsPartFile) {
				$partStorage->unlink($internalPartPath);
			}
			$this->convertToSabreException($e);
		}

		try {
			$view = \OC\Files\Filesystem::getView();
			if ($view) {
				$run = $this->emitPreHooks($exists);
			} else {
				$run = true;
			}

			try {
				$this->changeLock(ILockingProvider::LOCK_EXCLUSIVE);
			} catch (LockedException $e) {
				if ($needsPartFile) {
					$partStorage->unlink($internalPartPath);
				}
				throw new FileLocked($e->getMessage(), $e->getCode(), $e);
			}

			if ($needsPartFile) {
				// rename to correct path
				try {
					if ($run) {
						$renameOkay = $storage->moveFromStorage($partStorage, $internalPartPath, $internalPath);
						$fileExists = $storage->file_exists($internalPath);
					}
					if (!$run || $renameOkay === false || $fileExists === false) {
						\OCP\Util::writeLog('webdav', 'renaming part file to final file failed', \OCP\Util::ERROR);
						throw new Exception('Could not rename part file to final file');
					}
				} catch (ForbiddenException $ex) {
					throw new DAVForbiddenException($ex->getMessage(), $ex->getRetry());
				} catch (\Exception $e) {
					$partStorage->unlink($internalPartPath);
					$this->convertToSabreException($e);
				}
			}

			// since we skipped the view we need to scan and emit the hooks ourselves
			$storage->getUpdater()->update($internalPath);

			try {
				$this->changeLock(ILockingProvider::LOCK_SHARED);
			} catch (LockedException $e) {
				throw new FileLocked($e->getMessage(), $e->getCode(), $e);
			}

			if ($view) {
				$this->emitPostHooks($exists);
			}

			// allow sync clients to send the mtime along in a header
			if (isset($metadata['x-oc-mtime'])) {
				if ($this->fileView->touch($this->path, $metadata['x-oc-mtime'])) {
					$property['{DAV:}x-oc-mtime'] = 'accepted'; //TODO: not sure about that
				}
			}

			$this->refreshInfo();

		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable("Failed to check file size: " . $e->getMessage());
		}

		//TODO add proper attributes
		$etag = '"' . $this->getEtag() . '"';
		$property['{DAV:}etag'] = $etag; //TODO: not sure about that
		$property['{DAV:}oc-etag'] = $etag; //TODO: not sure about that
		$property['{DAV:}oc-fileid'] = $this->getFileId();//TODO: not sure about that
		return $property;
	}
}