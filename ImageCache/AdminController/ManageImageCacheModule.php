<?php

/**
 * Admin interface to the image proxy cache.
 *
 * The naming here is important, it must follow Manage*Module.controller.php for
 * it to be discovered by the system and have its static addCoreFeature method
 * called to add it to the core features.
 *
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

namespace Addons\ImageCache\AdminController;

use Addons\ImageCache\Controller\ImageCache;
use ElkArte\AbstractController;
use ElkArte\Action;
use ElkArte\Languages\Txt;
use ElkArte\SettingsForm\SettingsForm;

/**
 * Class ManageImageCacheModule_Controller
 */
class ManageImageCacheModule extends AbstractController
{
	/**
	 * Requires admin_forum permissions
	 *
	 * @uses ImageCache language file
	 */
	public function pre_dispatch()
	{
		Txt::load('ImageCache');
		isAllowedTo('admin_forum');
	}

	/**
	 * Default method
	 */
	public function action_index()
	{
		global $context;

		// Some many options
		$subActions = [
			'cleanimagecache' => [$this, 'action_cleanimagecache', 'permission' => 'admin_forum'],
			'settings' => [$this, 'action_imagecache_settings', 'permission' => 'admin_forum'],
		];

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
	 * Adds or removes the scheduled task from the system.  Adds when the
	 * core feature is enabled and removes it when it is disabled.
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
				['next_time' => 'int', 'time_offset' => 'int', 'time_regularity' => 'int', 'time_unit' => 'string', 'disabled' => 'int', 'task' => 'string'],
				[0, 45, 1, 'd', 0, 'remove_old_image_cache'],
				['id_task']
			);
		}
		else
		{
			$db->query('', '
				DELETE FROM {db_prefix}scheduled_tasks
				WHERE task = {string:task}',
				[
					'task' => 'remove_old_image_cache'
				]
			);
		}
	}

	/**
	 * Clears the cache of all image_cache_* files
	 */
	public function action_cleanimagecache()
	{
		checkSession();
		validateToken('admin-maint');

		// Remove them ALL
		$image_cache = new ImageCache();
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
		$settingsForm = new SettingsForm(SettingsForm::DB_ADAPTER);

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

		Txt::load('ImageCache');

		// Here are all the image cache settings, what all this for that :D
		$config_vars = [
			['desc', 'image_cache_desc'],
			['check', 'image_cache_enabled'],
			['check', 'image_cache_all'],
			['check', 'image_cache_nolink'],
			['int', 'image_cache_keep_days', 'postinput' => $txt['days_word'], 'subtext' => $txt['image_cache_keep_days_subnote']],
		];

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
