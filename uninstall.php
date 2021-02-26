<?php

/**
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2021 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.0
 *
 */

// If we have found SSI.php and we are outside of ELK, then we are running standalone.
if (file_exists(dirname(__FILE__) . '/SSI.php') && !defined('ELK'))
{
	require_once(dirname(__FILE__) . '/SSI.php');
}
elseif (!defined('ELK')) // If we are outside ELK and can't find SSI.php, then throw an error
{
	die('<b>Error:</b> Cannot install - please verify you put this file in the same place as Elkarte\'s SSI.php.');
}

global $modSettings;

require_once(SUBSDIR . '/Admin.subs.php');

// Turn off the image cache to ensure core features enabling code is
// triggered on any future update/install

// Remove scheduled task
$db = database();
$db->query('', '
	DELETE FROM {db_prefix}scheduled_tasks
	WHERE task = {string:task}',
	array(
		'task' => 'remove_old_image_cache'
	)
);

// Remove module hooks
disableModules('image_cache', array('admin'));
Hooks::instance()->disableIntegration('Image_Cache_Integrate');

// Remove enabled settings (core feature)
$setting_changes['image_cache_enabled'] = 0;
if (!empty($modSettings['admin_features']))
{
	$setting_changes['admin_features'] = str_replace(',ic', '', $modSettings['admin_features']);
}
updateSettings($setting_changes);
