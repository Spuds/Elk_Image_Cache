<?php

/**
 * Image cache proxy core functionality
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2022 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.6
 *
 */

/**
 * Class Image_Cache
 *
 * Provides all functions relating to running the image cache proxy
 *
 * - AbstractModel provides $db ($this_db) and $modSettings ($this->_modSettings)
 * for use in the extended class
 */
class Image_Cache extends AbstractModel
{
	/** @var int size of locally saved image */
	public $height = 768;

	/** @var int size of locally saved image */
	public $width = 1024;

	/** @var int number of times to retry a failed fetch */
	public $max_retry = 10;

	/** @var string where to save the fetched image */
	private $destination;

	/** @var bool if the image was fetched */
	private $success = false;

	/** @var string file hash name to prevent direct access */
	private $hash;

	/** @var time for the log to determine next fetch attempt */
	private $log_time;

	/** @var int number of times the image has failed to be retrieved from remote site */
	private $num_fail;

	/** @var string image file contents */
	private $data;

	/**
	 * Image_Cache constructor.
	 *
	 * @param Database|null $db
	 * @param string $file
	 */
	public function __construct($db = null, $file = '')
	{
		parent::__construct($db);

		$this->data = $file;
		$this->hash = $this->_imageCacheHash();
		$this->destination = CACHEDIR . '/img_cache_' . $this->hash;
	}

	/**
	 * Creates a hash code using the image name and our secret key
	 *
	 * - If no salt (secret key) has been set, creates a random one for use, and sets it
	 * in modsettings for future use
	 *
	 * @return string
	 */
	private function _imageCacheHash()
	{
		// What no hash sauce, then go ask Alice
		if (empty($this->_modSettings['imagecache_sauce']))
		{
			// Generate a 10 digit random hash.
			$tokenizer = new Token_Hash();
			$imagecache_sauce = $tokenizer->generate_hash();

			// Save it for all future uses
			updateSettings(array('imagecache_sauce' => $imagecache_sauce));
			$this->_modSettings['imagecache_sauce'] = $imagecache_sauce;
		}

		return hash_hmac('md5', $this->data, $this->_modSettings['imagecache_sauce']);
	}

	/**
	 * Checks if the image has previously been saved.
	 *
	 * - true if we have successfully (previously) saved the image
	 * - false if there is no record of the image
	 * - int, the number of times we have failed in trying to fetch the image
	 *
	 * @return bool|int
	 */
	public function getImageFromCacheTable()
	{
		$request = $this->_db->query('', '
			SELECT
			 	filename, log_time, num_fail
			FROM {db_prefix}image_cache
			WHERE filename = {string:filename}',
			array(
				'filename' => $this->hash,
			)
		);
		if ($this->_db->num_rows($request) == 0)
		{
			$this->num_fail = false;
		}
		else
		{
			list(, $this->log_time, $this->num_fail) = $this->_db->fetch_row($request);
			$this->num_fail = empty($this->num_fail) ? true : (int) $this->num_fail;
		}
		$this->_db->free_result($request);

		return $this->num_fail;
	}

	/**
	 * Will retry to fetch a previously failed attempt at caching an image
	 *
	 * What it does:
	 * - If the number of attempts has been exceeded, gives up
	 * - If the time gate / attempt value allows another attempt, does so
	 * - Attempts to ensure only one request makes the next attempt to avoid race/contention issues
	 */
	public function retryCreateImageCache()
	{
		// Time to give up ?
		if ($this->num_fail > $this->max_retry)
		{
			return;
		}

		// The more failures the longer we wait before the next attempt,
		// 10 attempts ending 1 week out from initial failure, approx as
		// 1min, 16min, 1.3hr, 4.2hr, 10.5hr, 21.6hr, 40hr, 2.8day, 4.5day, 1wk
		$delay = pow($this->num_fail, 4) * 60;
		$last_attempt = time() - $this->log_time;

		// Time to try again
		if ($last_attempt > $delay)
		{
			// Optimistic "locking" to try and prevent any race conditions
			$this->_db->query('', '
				UPDATE {db_prefix}image_cache
				SET num_fail = num_fail + 1
				WHERE filename = {string:filename}
					AND num_fail = {int:num_fail}',
				array(
					'filename' => $this->hash,
					'num_fail' => $this->num_fail
				)
			);

			// Only if we have success in updating the fail count is the attempt "ours" to make
			if ($this->_db->affected_rows() != 0)
			{
				$this->createCacheImage();
			}
		}
	}

	/**
	 * Main process loop for fetching and caching an image
	 */
	public function createCacheImage()
	{
		require_once(SUBSDIR . '/Graphics.subs.php');

		// Constrain the image to fix to our maximums
		$this->_setImageDimensions();

		// Keep png's as png's, all others to jpg
		$extension = 2;
		if (pathinfo($this->data, PATHINFO_EXTENSION) === 'gif')
		{
			$extension = 1;
		}
		elseif (pathinfo($this->data, PATHINFO_EXTENSION) === 'png')
		{
			$extension = 3;
		}

		// Create a "lesser" image for the local cache
		$this->success = resizeImageFile($this->data, $this->destination, $this->width, $this->height, $extension, false, false);

		// Log success or failure
		$this->_actOnResult();
	}

	/**
	 * Sets the saved image width/height based on acp settings or defaults
	 */
	private function _setImageDimensions()
	{
		// @todo ic_max_image_xxx are not exposed in acp
		$this->width = !empty($this->_modSettings['ic_max_image_height']) ? $this->_modSettings['ic_max_image_width'] : $this->width;
		$this->height = !empty($this->_modSettings['ic_max_image_height']) ? $this->_modSettings['ic_max_image_height'] : $this->height;
	}

	/**
	 * Based on success or failure on creating a cache image, determines next steps
	 */
	private function _actOnResult()
	{
		// Add or update the entry
		$this->_updateImageCacheTable();

		// Failure! ... show em a default mime thumbnail instead
		if ($this->success === false)
		{
			$this->_setTemporaryImage();
		}
	}

	/**
	 * Updates the image cache db table with the results of the attempt
	 */
	private function _updateImageCacheTable()
	{
		// Always update the line with success
		if ($this->success === true)
		{
			$this->_db->insert('replace',
				'{db_prefix}image_cache',
				array('filename' => 'string', 'log_time' => 'int', 'num_fail' => 'int'),
				array($this->hash, time(), 0),
				array('filename')
			);
		}

		// Add the line only if this is the first time it fails
		if ($this->success === false)
		{
			$this->_db->insert('ignore',
				'{db_prefix}image_cache',
				array('filename' => 'string', 'log_time' => 'int', 'num_fail' => 'int'),
				array($this->hash, time(), 1),
				array('filename')
			);
		}
	}

	/**
	 * On failure, saves our default mime image for use
	 */
	private function _setTemporaryImage()
	{
		global $settings;

		$source = $settings['theme_dir'] . '/images/mime_images/default.png';

		@copy($source, $this->destination);
	}

	/**
	 * Returns the current file hash
	 *
	 * @return string
	 */
	public function getImageCacheHash()
	{
		return $this->hash;
	}

	/**
	 * Update the log date, indicating last access only for a successful cache hit
	 *
	 * - Keeping the date of successful entries are used in maximum age
	 * - Updates once an hour to minimize db work
	 */
	public function updateImageCacheHitDate()
	{
		// Its in the cache and has not been touched in at least an hour
		if ($this->num_fail === true && $this->log_time + 3600 < time())
		{
			$this->_db->query('', '
				UPDATE {db_prefix}image_cache
				SET log_time = {int:log_time}
				WHERE filename = {string:filename}',
				array(
					'filename' => $this->hash,
					'log_time' => time(),
				)
			);
		}
	}

	/**
	 * Removes ALL image cache entries from the filesystem and db table.
	 *
	 * @return bool
	 */
	public function pruneImageCache()
	{
		// Remove '/img_cache_' files in our disk cache directory
		try
		{
			$files = new GlobIterator(CACHEDIR . '/img_cache_*', FilesystemIterator::SKIP_DOTS);

			foreach ($files as $file)
			{
				if ($file->getFileName() !== 'index.php' && $file->getFileName() !== '.htaccess')
				{
					@unlink($file->getPathname());
				}
			}
		}
		catch (UnexpectedValueException $e)
		{
			// @todo
		}

		// Finish off by clearing the image_cache table of all entries
		$this->_db->query('truncate_table', '
			TRUNCATE {db_prefix}image_cache',
			array(
			)
		);

		clearstatcache();

		return true;
	}
}
