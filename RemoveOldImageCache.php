<?php
/**
 * Schedule task interface to remove images in the cache that have not been accessed
 * in a given period of time
 *
 * @package ImageCache
 * @author Spuds
 * @copyright (c) 2022-2025 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 2.0.0
 *
 */

namespace ElkArte\ScheduledTasks\Tasks;

use ElkArte\Themes\ThemeLoader;

/**
 * Remove cache files that are past their expiration data
 *
 * Class RemoveOldImageCache
 *
 * @package ScheduledTask
 */
class RemoveOldImageCache implements ScheduledTaskInterface
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

		// Keeping them forever, I guess
		if (empty($modSettings['image_cache_keep_days']))
		{
			return true;
		}

		// We need this for language items
		ThemeLoader::loadEssentialThemeData();

		// Back up in time image_cache_keep_days
		$pruneDate = time() - ($modSettings['image_cache_keep_days'] * 86400);

		// All files that are older than pruneDate
		$files = $db->fetchQuery('
			SELECT 
				filename
			FROM  {db_prefix}image_cache
			WHERE log_time < {int:prune_time}',
			[
				'prune_time' => $pruneDate,
			]
		)->fetch_callback(
			static function ($row) {
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
				[
					'files' => $files,
				]
			);
		}

		return true;
	}
}
