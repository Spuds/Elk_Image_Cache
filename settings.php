<?php

/**
 * @package ImageCache
 * @author Spuds
 * @copyright (c) 2022-2025 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * https://mozilla.org/MPL/1.1/.
 *
 * @version 2.0.0
 *
 */

use ElkArte\Hooks;

if (file_exists(__DIR__ . '/SSI.php') && !defined('ELK'))
{
	require_once(__DIR__ . '/SSI.php');
}
elseif (!defined('ELK'))
{
	exit('<b>Error:</b> Cannot install - please verify you put this in the same place as ELK\'s index.php.');
}

global $modSettings, $package_cache;

$updates = array(
	'admin_features' => $modSettings['admin_features'] . ',ic'
);

updateSettings($updates);

// Enable the core feature by default on install
Hooks::instance()->enableIntegration('\Addons\ImageCache\ImageCacheIntegrate');

if (ELK === 'SSI')
{
	echo 'Settings changes were carried out successfully.';
}
