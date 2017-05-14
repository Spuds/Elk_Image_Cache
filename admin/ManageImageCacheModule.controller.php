<?php

/**
 * Admin interface to the image proxy cache.
 *
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

/**
 * Class ManageImageCacheModule_Controller
 */
class ManageImageCacheModule_Controller extends Action_Controller
{
	/**
	 * Boards settings form.
	 * @var Settings_Form
	 */
	protected $_imagecacheSettings;

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
		// We're working with them settings here.
		require_once(SUBSDIR . '/SettingsForm.class.php');

		// Some many options
		$subActions = array(
			'cleanimagecache' => array($this, 'action_cleanimagecache', 'permission' => 'admin_forum'),
			'settings' => array($this, 'action_imagecache_settings', 'permission' => 'admin_forum'),
		);

		// Action control
		$action = new Action('manage_imagecache');

		// By default we want to manage settings, call integrate_sa_manage_imagecache
		$subAction = $action->initialize($subActions, 'settings');

		// Final bits
		$context['sub_action'] = $subAction;

		// Call the right function for this sub-action.
		$action->dispatch($subAction);
	}

	/**
	 * Adds or removes the scheduled task from the system.  Adds when the
	 * module is enabled and removes it when it is disabled.
	 *
	 * @param string $action 'add' to activate the task
	 */
	public function updateScheduleTask($action = '')
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
	 */
	public static function imagecache_integrate_routine_maintenance()
	{
		global $txt, $scripturl, $context;

		loadLanguage('ImageCache');

		$context['routine_actions'] += array(
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
		require_once(SUBSDIR . '/ImageCache.class.php');
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
		$this->_initImageCacheSettingsForm();

		// Get all settings
		$config_vars = $this->_imagecacheSettings->settings();

		// Setup the template.
		$context['page_title'] = $txt['image_cache_title'];
		$context['sub_template'] = 'show_settings';
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['image_cache_label'],
			'help' => '',
			'description' => $txt['image_cache_settings_description'],
		);

		// Saving them ?
		if (isset($_GET['save']))
		{
			checkSession();

			// Perhaps an addon exists, or wants to, for this module
			call_integration_hook('integrate_save_imagecache_settings');

			// On/Off scheduled task
			if (!empty($_POST['image_cache_enabled']))
				$this->updateScheduleTask('add');
			else
				$this->updateScheduleTask();

			Settings_Form::save_db($config_vars);
			redirectexit('action=admin;area=manageimagecache');
		}

		// Final settings...
		$context['post_url'] = $scripturl . '?action=admin;area=manageimagecache;save';
		$context['settings_title'] = $txt['image_cache_title'];

		// Prepare the settings...
		Settings_Form::prepare_db($config_vars);
	}

	/**
	 * Initialize the boardSettings form, with the current configuration
	 * options for admin board settings screen.
	 */
	private function _initImageCacheSettingsForm()
	{
		// Instantiate the form
		$this->_imagecacheSettings = new Settings_Form();

		// Initialize it with our settings
		$config_vars = $this->_settings();

		return $this->_imagecacheSettings->settings($config_vars);
	}

	/**
	 * Returns all image cache settings in config_vars format.
	 */
	private function _settings()
	{
		global $txt;

		// Here are all the image cache settings, what all this for that :D
		$config_vars = array(
			array('desc', 'image_cache_desc'),
			array('check', 'image_cache_enabled'),
			array('check', 'image_cache_all'),
			array('int', 'image_cache_maxsize', 'subtext' => $txt['image_cache_maxsize_subnote']),
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
