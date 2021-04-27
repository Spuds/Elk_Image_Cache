<?php

/**
 * Simple proxy to output a cached image.  Primarily intended to allow
 * viewing of http images on a https/ssl enabled ElkArte site
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2021 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.5
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

	/** @var mixed|string the image requested */
	private $_image = '';

	/** @var mixed|string the hash for the image */
	private $_hash = '';

	/**
	 * Elk_Proxy constructor.
	 */
	public function __construct()
	{
		global $boardurl, $modSettings;

		// Let the Elk out of the barn, 1.1 and 1.1.1+
		require_once(dirname(__FILE__) . '/bootstrap.php');
		if (class_exists('Bootstrap'))
		{
			require_once(dirname(__FILE__) . '/SSI.php');
		}
		else
		{
			define('ELK', 'SSI');
		}

		$this->_boardurl = $boardurl;

		$this->_req = HttpReq::instance();

		// Using the proxy, we need both the requested image and a hash
		if (isset($this->_req->query->image, $this->_req->query->hash))
		{
			$this->_image = urldecode($this->_req->getQuery('image', 'trim', 'none'));
			$this->_hash = $this->_req->getQuery('hash', 'trim', '');
			$this->_fileName = CACHEDIR . '/img_cache_' . hash_hmac('md5', $this->_image, $modSettings['imagecache_sauce']);
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
		if (!empty($this->_req->server->HTTP_IF_MODIFIED_SINCE))
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
		$this->_eTag = '"' . substr($this->_fileName . filemtime($this->_fileName), 0, 64) . '"';
		if (!empty($this->_req->server->HTTP_IF_NONE_MATCH) &&
			strpos($this->_req->server->HTTP_IF_NONE_MATCH, $this->_eTag) !== false)
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
		// Send the attachment headers.
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 525600 * 60) . ' GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($this->_fileName)) . ' GMT');
		header('Accept-Ranges: bytes');
		header('Connection: close');
		header('ETag: ' . $this->_eTag);
		header('Content-Type: image/png');

		$disposition = 'inline';
		$fileName = str_replace('"', '', $this->_fileName);

		// Send as UTF-8 if the name requires that
		$altName = '';
		if (preg_match('~[\x80-\xFF]~', $fileName))
		{
			$altName = "; filename*=UTF-8''" . rawurlencode($fileName);
		}

		header('Content-Disposition',$disposition . '; filename="' . $fileName . '"' . $altName);
		header('Cache-Control: max-age=' . (525600 * 60) . ', private');
		header('Content-Length: ' . $this->_fileSize);
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
}

// Send a cached image file
$proxy = new Elk_Proxy();
if ($proxy->isValidRequest())
{
	$proxy->sendImage();
}
