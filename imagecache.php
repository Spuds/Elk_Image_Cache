<?php

/**
 * Simple proxy to output a cached image.  Primarily intended to allow
 * viewing of http images on a https/ssl enabled ElkArte site
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2017 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.0
 *
 */

/**
 * Class Elk_Proxy
 */
class Elk_Proxy
{
	/** @var bool If they need to bypass the refer check */
	public $_overrideReferrer = false;
	/** @var HttpReq Holds instance of HttpReq object */
	protected $_req;
	/** @var string */
	private $_boardurl = '';
	/** @var string filename to send */
	private $_fileName = '';
	/** @var int size of the file */
	private $_fileSize = 0;
	/** @var string image etag */
	private $_eTag;
	/** @var string the image requested */
	private $_image = '';
	/** @var string the hash for the image */
	private $_hash = '';
	/** @var string the db hash for the image */
	private $_hash_hmac = '';
	/** @var bool if the image exists in the cache */
	private $_cache_hit = false;

	/**
	 * Elk_Proxy constructor.
	 */
	public function __construct()
	{
		global $boardurl, $modSettings;

		// Let the Elk out of the barn
		require_once(dirname(__FILE__) . '/SSI.php');

		$this->_boardurl = $boardurl;

		$this->_req = HttpReq::instance();

		// Using the proxy, we need both the requested image and a hash
		if (isset($_GET['image'], $_GET['hash']))
		{
			$this->_image = 'none';
			if (isset($_GET['image']))
				$this->_image = trim(urldecode($_GET['image']));

			$this->_hash = '';
			if (isset($_GET['hash']))
				$this->_hash = trim($_GET['hash']);

			$this->_hash_hmac = hash_hmac('md5', $this->_image, $modSettings['imagecache_sauce']);

			$this->_fileName = CACHEDIR . '/img_cache_' . $this->_hash_hmac . '.elk';
		}
	}

	/**
	 * Send the requested image and headers
	 */
	public function sendImage()
	{
		// This is done to clear any output that was made before now.
		while (ob_get_level() > 0)
		{
			@ob_end_clean();
		}

		$this->_fileSize = @filesize($this->_fileName);

		ob_start();
		header('Content-Encoding: none');

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
		if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $this->_cache_hit)
		{
			list ($modified_since) = explode(';', $_SERVER['HTTP_IF_MODIFIED_SINCE']);
			if (strtotime($modified_since) >= filemtime($this->_fileName))
			{
				@ob_end_clean();

				// Answer the question - no, it hasn't been modified ;).
				header('HTTP/1.1 304 Not Modified');
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
		$this->_eTag = '"' . substr($this->_hash . filemtime($this->_fileName), 0, 64) . '"';
		if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) &&
			strpos($_SERVER['HTTP_IF_NONE_MATCH'], $this->_eTag) !== false &&
			$this->_cache_hit)
		{
			@ob_end_clean();

			header('HTTP/1.1 304 Not Modified');
			exit(0);
		}
	}

	/**
	 * Send the headers for the image
	 */
	private function _sendHeaders()
	{
		// Set/Choose a mime for the header
		$size = getimagesize($this->_fileName);
		$mime = isset($size['mime']) ? $size['mime'] : 'image/jpg';

		// Send the attachment headers.
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($this->_fileName)) . ' GMT');
		header('Accept-Ranges: bytes');
		header('Connection: close');
		header('Content-Type: ' . $mime);
		header('Content-Disposition: inline');
		header('Content-Length: ' . $this->_fileSize);

		if ($this->_cache_hit)
		{
			header('ETag: ' . $this->_eTag);
			header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 525600 * 60) . ' GMT');
			header('Cache-Control: max-age=' . (525600 * 60) . ', private');
		}
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
		// Small files try readfile() first, failing that use file get contents
		elseif (@readfile($this->_fileName) === null)
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
		if ($this->_hash_hmac !== $this->_hash)
		{
			return false;
		}

		if (!$this->_isValidReferrer())
		{
			return false;
		}

		if (!file_exists($this->_fileName))
		{
			$this->_resetCacheEntry();
			return false;
		}

		return true;
	}

	/**
	 * Files being requested should only come from this site
	 *
	 * - Should we get a HTTP_REFERER then validate its correct.
	 * - Can't depend on this to always be enforces as there are many reasons HTTP_REFERER
	 * will be empty.
	 *
	 * @return bool
	 */
	private function _isValidReferrer()
	{
		$is_allowed = true;

		// If we have a HTTP_REFERER header, we make sure its from us
		$referer = (isset($this->_req->server->HTTP_REFERER)) ? $this->_req->server->HTTP_REFERER : false;
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

	/**
	 * Called to remove a db entry from the cache
	 *
	 * - If we receive a valid hash but can't find the file, this will
	 * clear the entry (should it exists) from the db as well
	 * - We seed all new requests with the default image, so it *should*
	 * always be found, but if not, we clear the db entry
	 */
	private function _resetCacheEntry()
	{
		// Use the image cache to do the necessary work
		require_once(SUBSDIR . '/ImageCache.class.php');
		$cache = new Image_Cache(database(), $this->_image);
		$cache->removeImageFromCacheTable();
	}

	/**
	 * Fetch and save a remote image
	 *
	 * - If image exists, updates last access date
	 * - If image does not exist, attempts to fetch it from the location
	 * - Should be called after isValidRequest
	 */
	public function fetchImage()
	{
		// Use the image cache to check availability of the image
		require_once(SUBSDIR . '/ImageCache.class.php');
		$cache = new Image_Cache(database(), $this->_image);
		$this->_cache_hit = $cache->getImageFromCacheTable();

		// True means we have the image, so we just update the access date
		if ($this->_cache_hit === true)
		{
			$cache->updateImageCacheHitDate();
		}
		// A false or numeric result means we need to try
		else
		{
			// A false result means we never tried to get this file
			if ($this->_cache_hit === false)
			{
				$cache->createCacheImage();
			}
			// A numeric means we have tried and failed
			else
			{
				$cache->retryCreateImageCache();
			}

			$this->_cache_hit = $cache->returnStatus();
		}
	}
}

// Fetch, save and send a cached image file
$proxy = new Elk_Proxy();
if ($proxy->isValidRequest())
{
	$proxy->fetchImage();
	$proxy->sendImage();
}
