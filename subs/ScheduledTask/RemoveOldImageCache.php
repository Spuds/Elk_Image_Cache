<?php
/**
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
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
		$files = $db->fetchQuery('
			SELECT 
				filename
			FROM  {db_prefix}image_cache
			WHERE log_time < {int:prune_time}',
			array(
				'prune_time' => $pruneDate,
			)
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