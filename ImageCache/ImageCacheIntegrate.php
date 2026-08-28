<?php

/**
 * Provides a "simple" image cache, intended for serving http images over https.
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

namespace Addons\ImageCache;

use Addons\ImageCache\AdminController\ManageImageCacheModule;
use Addons\ImageCache\Controller\ImageCache;
use BBC\Codes;
use ElkArte\Hooks;
use ElkArte\Languages\Txt;
use ElkArte\Menu\Menu;
use ElkArte\Helper\Util;

/**
 * Provides integration for managing and using image caching within the system.
 *
 * This class contains hooks for registering ImageCache-related actions, settings,
 * maintenance tasks, and administrative configurations. It also includes methods
 * for managing the caching behavior of images and determining whether an image
 * URL should be proxified/cache processed.
 *
 */
class ImageCacheIntegrate
{
	/** @var bool if js has already been loaded */
	static public $js_load = false;

	/**
	 * Register ImageCache hooks to the system
	 *
	 * This function is called only after the core feature is enabled. Enabling the
	 * core feature sets Addons\ImageCacheIntegrate as an enabled integration (via enableIntegration())
	 *
	 * The Hooks class makes static calls to ::register and ::settingsRegister for each class that
	 * was saved with enableIntegration() (stored in $modSettings['autoload_integrate']).
	 * $modSettings is updated during install process to include the new integration
	 *
	 * @return array
	 */
	public static function register()
	{
		global $modSettings;

		if (empty($modSettings['image_cache_enabled']))
		{
			return [];
		}

		// $hook, $function, $file
		return [
			['integrate_additional_bbc', '\Addons\ImageCache\ImageCacheIntegrate::integrate_additional_bbc'],
			['integrate_avatar', '\Addons\ImageCache\ImageCacheIntegrate::integrate_avatar'],
		];
	}

	/**
	 * Register ACP config hooks for setting options
	 *
	 * @return array
	 */
	public static function settingsRegister()
	{
		global $context;

		if (!in_array('ic', $context['admin_features'], true))
		{
			return [];
		}

		// $hook, $function, $file
		return [
			['integrate_routine_maintenance', '\Addons\ImageCache\ImageCacheIntegrate::ic_integrate_routine_maintenance'],
			['integrate_sa_manage_maintenance', 'ManageImageCacheModule_Controller::ic_integrate_sa_manage_maintenance'],
			['integrate_admin_areas', '\Addons\ImageCache\ImageCacheIntegrate::ic_integrate_admin_areas'],
			['integrate_admin_search', '\Addons\ImageCache\ImageCacheIntegrate::ic_integrate_admin_search']
		];
	}

	/**
	 * This is used to add a clear image cache entry to the routine maintenance screen
	 *
	 * @param array $routine
	 */
	public static function ic_integrate_routine_maintenance(&$routine)
	{
		global $txt, $scripturl;

		Txt::load('ImageCache');

		$routine += [
			'cleanimagecache' => [
				'url' => $scripturl . '?action=admin;area=manageimagecache;sa=cleanimagecache',
				'title' => $txt['maintain_imagecache'],
				'description' => $txt['maintain_imagecache_info'],
				'submit' => $txt['maintain_run_now'],
				'hidden' => [
					'session_var' => 'session_id',
					'admin-maint_token_var' => 'admin-maint_token',
				]
			]
		];
	}

	/**
	 * Used to add the ImageCache entry to the admin menu.
	 *
	 * @param Menu $admin_areas
	 */
	public static function ic_integrate_admin_areas($admin_areas)
	{
		global $txt, $context;

		Txt::load('ImageCache');

		// Set a new admin area, manageimagecache, to add to config after addonsettings
		$new_area['addons'] = [
			'manageimagecache' => [
				'label' => $txt['image_cache_title'],
				'controller' => 'ManageImageCacheModule',
				'function' => 'action_index',
				'namespace' => 'Addons\ImageCache\AdminController\\',
				'class' => 'i-admin i-directory',
				'permission' => ['admin_forum'],
				'enabled' => in_array('ic', $context['admin_features'], true),
			]
		];

		return $admin_areas->insertArea($new_area, 'addonsettings');
	}

	/**
	 * Used to add the ImageCache to the admin search.
	 *
	 * @param string[] $language_files
	 * @param string[] $include_files
	 * @param array $settings_search
	 */
	public static function integrate_admin_search(&$language_files, &$include_files, &$settings_search)
	{
		$language_files[] = 'ImageCache';
		$include_files[] = 'ManageImageCacheModule';
		$settings_search[] = ['settings_search', 'area=manageimagecache', ManageImageCacheModule::class];
	}

	/**
	 * Used to add the Image Cache entry to the Core Features list.  This static call is made from the
	 * coreFeatures class via method _discoverCoreFeatures.  The hook is discovered by naming conventions,
	 * here the file must be in Addons directory and be XYZIntegrate.php where XYZ is the name of the addon.
	 *
	 * @param array $core_features The core features array
	 */
	public static function addCoreFeature(&$core_features)
	{
		isAllowedTo('admin_forum');
		Txt::load('ImageCache');

		$core_features['ic'] = [
			'url' => getUrl('admin', ['action' => 'admin', 'area' => 'manageimagecache', '{session_data}']),
			'setting_callback' => function ($value) {
				// Toggle the removing of old image proxy files
				require_once(SUBSDIR . '/ScheduledTasks.subs.php');
				toggleTaskStatusByName('remove_old_image_cache', $value);

				// Enabling, register the integration and prepare the scheduled task
				if ($value)
				{
					ManageImageCacheModule::updateScheduleTask('add');
					calculateNextTrigger('remove_old_image_cache');

					Hooks::instance()->enableIntegration('\Addons\ImageCache\ImageCacheIntegrate');
					return ['disable_ic' => ''];
				}

				// Disabling, just forget about the integration
				ManageImageCacheModule::updateScheduleTask();
				updateSettings(['image_cache_enabled' => '']);
				Hooks::instance()->disableIntegration('\Addons\ImageCache\ImageCacheIntegrate');

				return ['disable_ic' => 1];
			},
		];
	}

	/**
	 * Determines if the image would require cache usage
	 *
	 * - Used by the updated BBC img codes added by integrate_additional_bbc
	 *
	 * @return \Closure
	 */
	public static function bbcValidateImageNeedsCache()
	{
		global $boardurl, $modSettings;

		self::$js_load = self::$js_load || !empty($modSettings['image_cache_nolink']);

		$always = !empty($modSettings['image_cache_all']);

		// Return a closure function for the bbc code
		return static function (&$data) use ($boardurl, $always) {
			$doCache = self::cacheNeedsImage($boardurl, $data, $always);

			if ($doCache === false)
			{
				return false;
			}

			// Flag the loading of js
			if (self::$js_load === false)
			{
				self::$js_load = true;
				loadJavascriptFile('imagecache.js', ['defer' => true]);
			}

			$data = self::proxifyImage($data);

			return true;
		};
	}

	/**
	 * Stores the image at the URL passed in the cache.
	 *
	 * @param string $imageUrl
	 *
	 * @return string
	 */
	protected static function proxifyImage($imageUrl)
	{
		global $boardurl, $txt;

		// Use the image cache to check availability
		$proxy = new ImageCache($imageUrl);
		$cache_hit = $proxy->getImageFromCacheTable();

		// A false or numeric result means we need to try
		if ($cache_hit === true)
		{
			$proxy->updateImageCacheHitDate();
		}
		elseif ($cache_hit === false)
		{
			$proxy->createCacheImage();
		}
		// A numeric means we have tried and failed
		else
		{
			$proxy->retryCreateImageCache();
		}

		// Make sure we have the language loaded
		if (!isset($txt['image_cache_warn_ext']))
		{
			Txt::load('ImageCache');
		}

		return $boardurl . '/imagecache.php?image=' . urlencode($imageUrl) . '&hash=' . $proxy->getImageCacheHash() . '" rel="cached" data-hash="' . $proxy->getImageCacheHash() . '" data-warn="' . Util::htmlspecialchars($txt['image_cache_warn_ext']) . '" data-url="' . Util::htmlspecialchars($imageUrl);
	}

	/**
	 * Determines if a certain URL needs to be cached, given the board url.
	 *
	 * @param string $boardurl
	 * @param string $imageurl
	 * @param bool $always
	 *
	 * @return bool
	 */
	protected static function cacheNeedsImage($boardurl, $imageurl, $always)
	{
		$imageurl = addProtocol($imageurl);
		$parseBoard = parse_url($boardurl);
		$parseImg = parse_url($imageurl);

		// No need if it's already on this site (like uploaded avatars)
		if (empty($parseImg) || $parseImg['host'] === $parseBoard['host'])
		{
			return false;
		}

		// No need to cache an image that is not going over https, or is already https over https
		if (!$always && (empty($parseImg['scheme']) || $parseBoard['scheme'] === 'http' || $parseBoard['scheme'] === $parseImg['scheme']))
		{
			return false;
		}

		return true;
	}

	/**
	 * Replaces the href from $avatar with the proxy if needed.
	 *
	 * @param array $avatar
	 * @return bool
	 */
	public static function integrate_avatar(&$avatar)
	{
		global $boardurl, $modSettings;

		$always = !empty($modSettings['image_cache_all']);

		if (!isset($avatar['href']))
		{
			return false;
		}

		if (self::cacheNeedsImage($boardurl, $avatar['href'], $always))
		{
			$proxy_href = self::proxifyImage($avatar['href']);
			$avatar['image'] = str_replace($avatar['href'], $proxy_href, $avatar['image']);
			$avatar['href'] = $proxy_href;
			$avatar['url'] = $proxy_href;
		}

		return true;
	}

	/**
	 * $codes will be populated with what other addons, modules, etc. have added to the system
	 * but will not contain the default codes.
	 *
	 * Codes added here will parse before any default ones effectively overwriting them as
	 * default codes are appended to this array.
	 *
	 * Your alternative is to use bbc_codes_parsing where you could change the default codes directly.  Problem
	 * is that you need to be aware of other addons tinkering with the same codes.
	 *
	 * @param array $codes
	 */
	public static function integrate_additional_bbc(&$codes)
	{
		loadCSSFile('imagecache.css');
		Txt::load('ImageCache');

		// Disabled tags?
		$disabledBBC = empty($modSettings['disabledBBC']) ? [] : explode(',', $modSettings['disabledBBC']);
		$disabled = in_array('img', $disabledBBC, true);

		// Add Image Cache codes
		$codes = array_merge($codes, [
			[
				Codes::ATTR_TAG => 'img',
				Codes::ATTR_TYPE => Codes::TYPE_UNPARSED_CONTENT,
				Codes::ATTR_DISABLED => $disabled,
				Codes::ATTR_PARAM => [
					'width' => [
						Codes::PARAM_ATTR_VALUE => 'width:100%;max-width:$1px;',
						Codes::PARAM_ATTR_MATCH => '(\d+)',
						Codes::PARAM_ATTR_OPTIONAL => true,
					],
					'height' => [
						Codes::PARAM_ATTR_VALUE => 'max-height:$1px;',
						Codes::PARAM_ATTR_MATCH => '(\d+)',
						Codes::PARAM_ATTR_OPTIONAL => true,
					],
					'title' => [
						Codes::PARAM_ATTR_MATCH => '(.+?)',
						Codes::PARAM_ATTR_OPTIONAL => true,
					],
					'alt' => [
						Codes::PARAM_ATTR_MATCH => '(.+?)',
						Codes::PARAM_ATTR_OPTIONAL => true,
					],
				],
				Codes::ATTR_CONTENT => '<img src="$1" title="{title}" alt="{alt}" style="{width}{height}" class="bbc_img resized" data-bbcexpandimage="1" />',
				Codes::ATTR_VALIDATE => $disabled ? null : self::bbcValidateImageNeedsCache(),
				Codes::ATTR_DISABLED_CONTENT => '($1)',
				Codes::ATTR_BLOCK_LEVEL => false,
				Codes::ATTR_AUTOLINK => false,
				Codes::ATTR_LENGTH => 3,
			],
			[
				Codes::ATTR_TAG => 'img',
				Codes::ATTR_TYPE => Codes::TYPE_UNPARSED_CONTENT,
				Codes::ATTR_CONTENT => '<img src="$1" alt="" class="bbc_img" />',
				Codes::ATTR_VALIDATE => $disabled ? null : self::bbcValidateImageNeedsCache(),
				Codes::ATTR_DISABLED => $disabled,
				Codes::ATTR_DISABLED_CONTENT => '($1)',
				Codes::ATTR_BLOCK_LEVEL => false,
				Codes::ATTR_AUTOLINK => false,
				Codes::ATTR_LENGTH => 3,
			]
		]);
	}
}
