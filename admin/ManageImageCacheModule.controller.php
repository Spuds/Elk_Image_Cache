<?php

/**
 * Admin interface to the image proxy cache.
 *
 * The naming here is important, it must follow Manage*Module.controller.php for
 * it to be discovered by the system and have its static addCoreFeature method
 * called to add it to the core features.
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2022 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.3
 *
 */

/**
 * Class ManageImageCacheModule_Controller
 */
class ManageImageCacheModule_Controller extends Action_Controller
{
	/**
	 * Requires admin_forum permissions
	 *
	 * @uses ImageCache language file
	 */
	public function pre_dispatch()
	{
		loadLanguage('ImageCache');
		isAllowedTo('admin_forum');
	}

	/**
	 * Default method
	 */
	public function action_index()
	{
		global $context;

		// Some many options
		$subActions = array(
			'cleanimagecache' => array($this, 'action_cleanimagecache', 'permission' => 'admin_forum'),
			'settings' => array($this, 'action_imagecache_settings', 'permission' => 'admin_forum'),
		);

		// Action control
		$action = new Action('manage_imagecache');

		// By default, we want to manage settings, call integrate_sa_manage_imagecache
		$subAction = $action->initialize($subActions, 'settings');

		// Final bits
		$context['sub_action'] = $subAction;

		// Call the right function for this sub-action.
		$action->dispatch($subAction);
	}

	/**
	 * Used to add the Image Cache entry to the Core Features list.
	 *
	 * - Called statically from the CoreFeatures Controller
	 *
	 * @param array $core_features The core features array
	 */
	public static function addCoreFeature(&$core_features)
	{
		isAllowedTo('admin_forum');
		loadLanguage('ImageCache');

		$core_features['ic'] = array(
			'url' => 'action=admin;area=manageimagecache',
			'settings' => array(
				'image_cache_enabled' => 1,
			),
			'setting_callback' => function ($value) {
				// Toggle the removing of old image proxy files
				require_once(SUBSDIR . '/ScheduledTasks.subs.php');
				toggleTaskStatusByName('remove_old_image_cache', $value);

				$modules = array('admin');

				// Enabling, register the modules and prepare the scheduled task
				if ($value)
				{
					ManageImageCacheModule_Controller::updateScheduleTask('add');
					enableModules('image_cache', $modules);
					calculateNextTrigger('remove_old_image_cache');
					Hooks::instance()->enableIntegration('Image_Cache_Integrate');
				}
				// Disabling, just forget about the modules
				else
				{
					ManageImageCacheModule_Controller::updateScheduleTask();
					disableModules('image_cache', $modules);
					Hooks::instance()->disableIntegration('Image_Cache_Integrate');
				}
			},
		);
	}

	/**
	 * Adds or removes the scheduled task from the system.  Adds when the
	 * module is enabled and removes it when it is disabled.
	 *
	 * This must be a static method as it is called from static addCoreFeature method
	 *
	 * @param string $action 'add' to activate the task
	 */
	public static function updateScheduleTask($action = '')
	{
		$db = database();

		if ($action === 'add')
		{
			$db->insert('ignore',
				'{db_prefix}scheduled_tasks',
				array('next_time' => 'int', 'time_offset' => 'int', 'time_regularity' => 'int', 'time_unit' => 'string', 'disabled' => 'int', 'task' => 'string'),
				array(0, 45, 1, 'd', 0, 'remove_old_image_cache'),
				array('id_task')
			);
		}
		else
		{
			$db->query('', '
				DELETE FROM {db_prefix}scheduled_tasks
				WHERE task = {string:task}',
				array(
					'task' => 'remove_old_image_cache'
				)
			);
		}
	}

	/**
	 * This is used to add a clear image cache entry to the routine maintenance screen
	 *
	 * @param array $routine
	 */
	public static function ic_integrate_routine_maintenance(&$routine)
	{
		global $txt, $scripturl;

		loadLanguage('ImageCache');

		$routine += array(
			'cleanimagecache' => array(
				'url' => $scripturl . '?action=admin;area=manageimagecache;sa=cleanimagecache',
				'title' => $txt['maintain_imagecache'],
				'description' => $txt['maintain_imagecache_info'],
				'submit' => $txt['maintain_run_now'],
				'hidden' => array(
					'session_var' => 'session_id',
					'admin-maint_token_var' => 'admin-maint_token',
				)
			)
		);
	}

	/**
	 * Clears the cache of all image_cache_* files
	 */
	public function action_cleanimagecache()
	{
		checkSession();
		validateToken('admin-maint');

		// Remove them ALL
		$image_cache = new Image_Cache();
		$image_cache->pruneImageCache();

		// Back to maintenance
		redirectexit('action=admin;area=maintain;sa=routine;done=maintain_imagecache');
	}

	/**
	 * Modify any setting related to the image cache proxy.
	 *
	 * - Requires the admin_forum permission.
	 * - Accessed from ?action=admin;area=manageimagecache
	 *
	 * @uses Admin template, edit_topic_settings sub-template.
	 */
	public function action_imagecache_settings()
	{
		global $context, $txt, $scripturl;

		// Initialize the form
		$settingsForm = new Settings_Form(Settings_Form::DB_ADAPTER);

		// Initialize it with our settings
		$settingsForm->setConfigVars($this->_settings());

		// Setup the template.
		$context['page_title'] = $txt['image_cache_title'];
		$context['sub_template'] = 'show_settings';
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['image_cache_label'],
			'help' => '',
			'description' => $txt['image_cache_settings_description'],
		);

		// Saving them ?
		if (isset($this->_req->query->save))
		{
			checkSession();

			// Perhaps an addon exists, or wants to, for this module
			call_integration_hook('integrate_save_imagecache_settings');

			$settingsForm->setConfigValues((array) $this->_req->post);
			$settingsForm->save();
			redirectexit('action=admin;area=manageimagecache');
		}

		// Final settings...
		$context['post_url'] = $scripturl . '?action=admin;area=manageimagecache;save';
		$context['settings_title'] = $txt['image_cache_title'];

		// Prepare the settings...
		$settingsForm->prepare();
	}

	/**
	 * Returns all image cache settings in config_vars format.
	 */
	private function _settings()
	{
		global $txt;

		loadLanguage('ImageCache');

		// Here are all the image cache settings, what all this for that :D
		$config_vars = array(
			array('desc', 'image_cache_desc'),
			array('check', 'image_cache_enabled'),
			array('check', 'image_cache_all'),
			array('check', 'image_cache_nolink'),
			array('int', 'image_cache_keep_days', 'postinput' => $txt['days_word'], 'subtext' => $txt['image_cache_keep_days_subnote']),
		);

		// Maybe an addon wants to add more settings.
		call_integration_hook('integrate_modify_imagecache_settings', array(&$config_vars));

		return $config_vars;
	}

	/**
	 * Return the form settings for use in admin search
	 */
	public function settings_search()
	{
		return $this->_settings();
	}
}
