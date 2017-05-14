<?php

/**
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

// If we have found SSI.php and we are outside of ELK, then we are running standalone.
if (file_exists(dirname(__FILE__) . '/SSI.php') && !defined('ELK'))
	require_once(dirname(__FILE__) . '/SSI.php');
elseif (!defined('ELK')) // If we are outside ELK and can't find SSI.php, then throw an error
	die('<b>Error:</b> Cannot install - please verify you put this file in the same place as Elkarte\'s SSI.php.');

global $db_prefix, $db_package_log;

$dbtbl = db_table();
$db = database();

// Create the image_cache table
$dbtbl->db_create_table($db_prefix . 'image_cache',
	array(
		array(
			'name' => 'filename',
			'type' => 'varchar',
			'size' => 32,
		),
		array(
			'name' => 'log_time',
			'type' => 'int',
			'size' => 10,
		),
		array(
			'name' => 'num_fail',
			'type' => 'tinyint',
			'size' => 2,
		),
	),
	array(
		array(
			'name' => 'filename',
			'type' => 'primary',
			'columns' => array('filename'),
		),
	),
	array(),
	'ignore');

// Add the scheduled task
$row = array(
	'method' => 'ignore',
	'table_name' => '{db_prefix}scheduled_tasks',
	'columns' => array(
		'next_time' => 'int',
		'time_offset' => 'int',
		'time_regularity' => 'int',
		'time_unit' => 'string',
		'disabled' => 'int',
		'task' => 'string',
	),
	'data' => array (1231542000, 39620, 1, 'd', 1, 'remove_old_image_cache'),
	'keys' => array('id_task'),
);
$db->insert($row['method'], $row['table_name'], $row['columns'], $row['data'], $row['keys']);

$db_package_log = $dbtbl->package_log();
