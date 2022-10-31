<?php
/**
 * Schedule task interface to remove images in the cache that have not been accessed
 * in a given period of time
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2022 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.0
 *
 */

namespace ElkArte\sources\subs\ScheduledTask;

/**
 * Remove cache files that are past their expiration data
 *
 * Class RemoveOldImageCache
 *
 * @package ScheduledTask
 */
class Remove_Old_Image_Cache implements Scheduled_Task_Interface
{
	/**
	 * Scheduled task for removing old image files from the cache
	 *
	 * @return bool
	 */
	public function run()
	{
		global $modSettings;

		$db = database();

		// Keeping them forever I guess
		if (empty($modSettings['image_cache_keep_days']))
		{
			return true;
		}

		// We need this for language items
		loadEssentialThemeData();

		// Back up in time image_cache_keep_days
		$pruneDate = time() - ($modSettings['image_cache_keep_days'] * 86400);

		// All files that are older than pruneDate
		$files = $db->fetchQueryCallback('
			SELECT 
				filename
			FROM  {db_prefix}image_cache
			WHERE log_time < {int:prune_time}',
			array(
				'prune_time' => $pruneDate,
			),
			function ($row) {
				return $row['filename'];
			}
		);

		// Remove the files
		foreach ($files as $file)
		{
			@unlink(CACHEDIR . '/img_cache_' . $file);
		}

		// Remove the db entry's
		if (!empty($files))
		{
			$db->query('', '
			DELETE FROM {db_prefix}image_cache
			WHERE filename IN ({array_string:files})',
				array(
					'files' => $files,
				)
			);
		}

		return true;
	}
}
