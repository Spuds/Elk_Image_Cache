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

/**
 * Class ImageCache_Admin_Module
 *
 * - Adds the image cache admin menu when its core feature is enabled
 * - All modules under the modules directory are discovered by the system
 * and their hooks method is called.
 */
class ImageCache_Admin_Module implements ElkArte\sources\modules\Module_Interface
{
	/**
	 * The method called by the EventManager to find out which trigger the
	 * module is attached to and which parameters the listener wants to receive.
	 *
	 * @param \Event_Manager $eventsManager an instance of the event manager
	 *
	 * @return array
	 */
	public static function hooks(\Event_Manager $eventsManager)
	{
		return array(
			array('addMenu', array('ImageCache_Admin_Module', 'addMenu'), array()),
			array('addSearch', array('ImageCache_Admin_Module', 'addSearch'), array()),
		);
	}

	/**
	 * Used to add the ImageCache entry to the admin menu.
	 *
	 * @param mixed[] $admin_areas The admin menu array
	 */
	public function addMenu(&$admin_areas)
	{
		global $txt, $context;

		loadLanguage('ImageCache');

		// Set a new admin area
		$admin_areas['config']['areas']['manageimagecache'] = array(
			'label' => $txt['image_cache_title'],
			'controller' => 'ManageImageCacheModule_Controller',
			'function' => 'action_index',
			'icon' => 'transparent.png',
			'class' => 'admin_img_logs',
			'permission' => array('admin_forum'),
			'enabled' => in_array('ic', $context['admin_features']),
		);
	}

	/**
	 * Used to add the ImageCache entry to the admin search.
	 *
	 * @param string[] $language_files
	 * @param string[] $include_files
	 * @param mixed[] $settings_search
	 */
	public function addSearch(&$language_files, &$include_files, &$settings_search)
	{
		$language_files[] = 'ImageCache';
		$include_files[] = 'ManageImageCacheModule.controller';
		$settings_search[] = array('settings_search', 'area=manageimagecache', 'ManageImageCacheModule_Controller');
	}
}