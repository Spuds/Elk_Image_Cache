<?php

/**
 * Proxy to output a cached image.  Primarily intended to allow
 * viewing of http images on a https/ssl enabled ElkArte site
 *
 * @package ImageCache
 * @author Spuds
 * @copyright (c) 2021-2025 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * https://mozilla.org/MPL/1.1/.
 *
 * @version 2.0.0
 *
 */

use ElkArte\Helper\FileFunctions;
use ElkArte\Helper\HttpReq;
use ElkArte\Http\Headers;

/**
 * Class ElkProxy
 */
class ElkProxy
{
	/** @var bool If they need to bypass the refer check */
	public $_overrideReferrer = true;

	/** @var HttpReq Holds instance of HttpReq object */
	protected $_req;

	/** @var string */
	private $_boardurl = '';

	/** @var string filename to send */
	private $_fileName = '';

	/** @var string filename to send */
	private $_fileExt = 'jpeg';

	/** @var int size of the file */
	private $_fileSize = 0;

	/** @var string image etag */
	private $_eTag;

	/** @var mixed|string the image requested */
	private $_image = '';

	/** @var mixed|string the hash for the image */
	private $_hash = '';

	/**
	 * ElkProxy constructor.
	 */
	public function __construct()
	{
		global $boardurl, $modSettings;

		// Let the Elk out of the barn
		require_once(__DIR__ . '/bootstrap.php');
		require_once(__DIR__ . '/SSI.php');

		$this->_boardurl = $boardurl;
		$this->_req = HttpReq::instance();

		// Using the proxy, we need both the requested image and a hash
		if (isset($this->_req->query->image, $this->_req->query->hash))
		{
			$this->_image = urldecode($this->_req->getQuery('image', 'trim', 'none'));
			$this->_hash = $this->_req->getQuery('hash', 'trim', '');
			// Strip query/fragment before determining extension
			$image = parse_url($this->_image, PHP_URL_PATH);
			$this->_fileExt = strtolower(pathinfo($image, PATHINFO_EXTENSION));
			$this->_fileExt = in_array($this->_fileExt, ['jpg', 'jpeg']) ? 'jpeg' : $this->_fileExt;
			$this->_fileName = CACHEDIR . '/img_cache_' . hash_hmac('md5', $this->_image, $modSettings['imagecache_sauce']);
		}
	}

	/**
	 * Send the requested image and headers
	 */
	public function sendImage()
	{
		// This is done to clear any output made before now.
		while (ob_get_level() > 0)
		{
			@ob_end_clean();
		}

		$this->_fileSize = FileFunctions::instance()->fileSize($this->_fileName);

		ob_start();

		// If it hasn't been modified, then you already have it
		$this->_checkModifiedSince();

		// Check whether an ETag was sent back
		$this->_checkEtag();

		// Send the attachment headers.
		$this->_sendHeaders();

		// Now send them the meaningful bits
		$this->_sendImageFile();

		obExit(false);
	}

	/**
	 * If the file has not been changed since the last request, then you have it
	 */
	private function _checkModifiedSince()
	{
		// If it hasn't been modified since the last time this attachment was retrieved,
		// there's no need to send it again.
		if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']))
		{
			[$modified_since] = explode(';', $_SERVER['HTTP_IF_MODIFIED_SINCE']);
			if (strtotime($modified_since) >= filemtime($this->_fileName))
			{
				@ob_end_clean();

				// Answer the question - no, it hasn't been modified ;).
				$headers = Headers::instance();
				$headers
					->removeHeader('all')
					->httpCode(304)
					->sendHeaders();
				exit(0);
			}
		}
	}

	/**
	 * If the browser has sent an etag, check to see if we need to send the image or not
	 */
	private function _checkEtag()
	{
		// Check whether the ETag was sent back, and cache based on that...
		$this->_eTag = '"' . substr($this->_fileName . filemtime($this->_fileName), 0, 64) . '"';
		if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && strpos($_SERVER['HTTP_IF_NONE_MATCH'], $this->_eTag) !== false)
		{
			@ob_end_clean();
			$headers = Headers::instance();
			$headers
				->removeHeader('all')
				->httpCode(304)
				->sendHeaders();
			exit(0);
		}
	}

	/**
	 * Send the headers for the image
	 */
	private function _sendHeaders()
	{
		// Send the attachment headers.
		$headers = Headers::instance();
		$headers
			->header('Expires', gmdate('D, d M Y H:i:s', time() + 525600 * 60) . ' GMT')
			->header('Last-Modified', gmdate('D, d M Y H:i:s', filemtime($this->_fileName)) . ' GMT')
			->header('Accept-Ranges', 'bytes')
			->header('Connection', 'close')
			->header('ETag', $this->_eTag)
			->header('Content-Type', 'image/' . $this->_fileExt);

		$headers->setAttachmentFileParams($this->_fileExt, $this->_fileName, 'inline');

		// Set the content length, since it is an image we don't compress
		$headers
			->header('Content-Length', $this->_fileSize)
			->sendHeaders();
	}

	/**
	 * Send the actual bytes of the image using the best method available.
	 *
	 * - Since this is an image which is already compressed, we don't try to use
	 * output buffer compression.
	 */
	private function _sendImageFile()
	{
		// Try to buy some time...
		detectServer()->setTimeLimit(300);

		// We don't want to overflow the buffer for large files
		if ($this->_fileSize > 4194304)
		{
			$fp = fopen($this->_fileName, 'rb');
			while (!feof($fp))
			{
				echo fread($fp, 8192);
				flush();
			}
			fclose($fp);
		}
		// Small files use file get contents
		else
		{
			echo file_get_contents($this->_fileName);
		}
	}

	/**
	 * Checks the request to make sure it is valid, this is not an open proxy
	 *
	 * - Sent hash is correct for this image file
	 * - The file exists
	 * - Refer and urls come from this site
	 *
	 * @return bool
	 */
	public function isValidRequest()
	{
		global $modSettings;

		$hash = hash_hmac('md5', $this->_image, $modSettings['imagecache_sauce']);

		if ($hash !== $this->_hash)
		{
			return false;
		}

		if (!file_exists($this->_fileName))
		{
			return false;
		}

		if (!$this->_isValidReferrer())
		{
			return false;
		}

		return true;
	}

	/**
	 * Files being requested should only come from this site
	 *
	 * - Should we get a HTTP_REFERER then validate its correct.
	 * - Can't depend on this to always be enforced as there are many reasons HTTP_REFERER
	 * will be empty.
	 *
	 * @return bool
	 */
	private function _isValidReferrer()
	{
		$is_allowed = true;

		// If we have a HTTP_REFERER header, we make sure its from us
		$referer = $this->_req->server->HTTP_REFERER ?? false;
		if (!empty($referer))
		{
			// It should be from our server
			$refererParts = parse_url($referer);
			if (!empty($refererParts['host']))
			{
				$requestParts = parse_url($this->_boardurl);
				if (!empty($requestParts['host']))
				{
					if ($refererParts['host'] !== $requestParts['host'])
					{
						$is_allowed = false;
					}
				}
			}
		}

		return $is_allowed;
	}
}

// Send a cached image file
$proxy = new ElkProxy();
if ($proxy->isValidRequest())
{
	$proxy->sendImage();
}
