<?php

/**
 * Provides a simple image cache, intended for serving http images over https.
 *
 * For proper auto detection, this file must be located in SUBSDIR and
 * follow naming conventions XxxYYY.integrate => Xxx_Yyy_Integrate
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2021 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.5
 *
 */

use BBC\Codes;

/**
 * Class Image_Cache_Integrate
 *
 * - For proper auto detection, this file must be located in SUBSDIR and named xxx.integrate
 * - The class must be xxx_Integrate
 * - collection of static methods
 */
class Image_Cache_Integrate
{
	static public $js_load = false;

	/**
	 * Register ImageCache hooks to the system
	 *
	 * - register method is called statically from loadIntegrations in Hooks.class
	 * - used here to update bbc img tag rendering and avatar save in profile
	 *
	 * @return array
	 */
	public static function register()
	{
		global $modSettings;

		if (empty($modSettings['image_cache_enabled']))
		{
			return array();
		}

		// $hook, $function, $file
		return array(
			array('integrate_additional_bbc', 'Image_Cache_Integrate::integrate_additional_bbc'),
			array('integrate_avatar', 'Image_Cache_Integrate::integrate_avatar'),
		);
	}

	/**
	 * Register ACP hooks for setting values in various areas
	 *
	 * - settingsRegister method is called statically from loadIntegrationsSettings in Hooks.class
	 * - used here to add ACP maintenance functions for the image cache
	 *
	 * @return array
	 */
	public static function settingsRegister()
	{
		// $hook, $function, $file
		return array(
			// Clear image cache in routine maintenance
			array('integrate_routine_maintenance', 'ManageImageCacheModule_Controller::ic_integrate_routine_maintenance'),
			// Clear image cache in scheduled tasks
			array('integrate_sa_manage_maintenance', 'ManageImageCacheModule_Controller::ic_integrate_sa_manage_maintenance'),
		);
	}

	/**
	 * Determines if the image would require cache usage
	 *
	 * - Used by the updated BBC img codes added by integrate_additional_bbc
	 *
	 * @return Closure
	 */
	public static function bbcValidateImageNeedsCache()
	{
		global $boardurl, $modSettings;

		self::$js_load = self::$js_load || !empty($modSettings['image_cache_nolink']);

		// Trickery for php5.3
		$js_loaded =& self::$js_load;
		$always = !empty($modSettings['image_cache_all']);

		// Return a closure function for the bbc code
		return function (&$tag, &$data, $disabled) use ($boardurl, &$js_loaded, $always)
		{
			$doCache = self::cacheNeedsImage($boardurl, $data, $always);

			if ($doCache === false)
			{
				return false;
			}

			// Flag the loading of js
			if ($js_loaded === false)
			{
				$js_loaded = true;
				loadJavascriptFile('imagecache.js', array('defer' => true));
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
		$proxy = new Image_Cache(database(), $imageUrl);
		$cache_hit = $proxy->getImageFromCacheTable();

		// A false or numeric result means we need to try
		if ($cache_hit === true)
		{
			$proxy->updateImageCacheHitDate();
		}
		else
		{
			// A false result means we never tried to get this file
			if ($cache_hit === false)
			{
				$proxy->createCacheImage();
			}
			// A numeric means we have tried and failed
			else
			{
				$proxy->retryCreateImageCache();
			}
		}

		// Make sure we have the language loaded
		if (!isset($txt['image_cache_warn_ext']))
		{
			loadLanguage('ImageCache');
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
	 * @return boolean
	 */
	protected static function cacheNeedsImage($boardurl, $imageurl, $always)
	{
		$imageurl = addProtocol($imageurl);

		$parseBoard = parse_url($boardurl);
		$parseImg = parse_url($imageurl);

		// No need if its already on this site (like uploaded avatars)
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
	 * $codes will be populated with what other addons, modules etc have added to the system
	 * but will not contain the default codes.
	 *
	 * Codes added here will parse before any default ones effectively over writing them as
	 * default codes are appended to this array.
	 *
	 * @param array $codes
	 */
	public static function integrate_additional_bbc(&$codes)
	{
		loadCSSFile('imagecache.css');
		loadLanguage('ImageCache');

		// Add Image Cache codes
		$codes = array_merge($codes, array(
			array(
				Codes::ATTR_TAG => 'img',
				Codes::ATTR_TYPE => Codes::TYPE_UNPARSED_CONTENT,
				Codes::ATTR_PARAM => array(
					'width' => array(
						Codes::PARAM_ATTR_VALUE => 'width:100%;max-width:$1px;',
						Codes::PARAM_ATTR_MATCH => '(\d+)',
						Codes::PARAM_ATTR_OPTIONAL => true,
					),
					'height' => array(
						Codes::PARAM_ATTR_VALUE => 'max-height:$1px;',
						Codes::PARAM_ATTR_MATCH => '(\d+)',
						Codes::PARAM_ATTR_OPTIONAL => true,
					),
					'title' => array(
						Codes::PARAM_ATTR_MATCH => '(.+?)',
						Codes::PARAM_ATTR_OPTIONAL => true,
					),
					'alt' => array(
						Codes::PARAM_ATTR_MATCH => '(.+?)',
						Codes::PARAM_ATTR_OPTIONAL => true,
					),
				),
				Codes::ATTR_CONTENT => '<img src="$1" alt="{alt}" style="{width}{height}" class="bbc_img resized" />',
				Codes::ATTR_VALIDATE => self::bbcValidateImageNeedsCache(),
				Codes::ATTR_DISABLED_CONTENT => '($1)',
				Codes::ATTR_BLOCK_LEVEL => false,
				Codes::ATTR_AUTOLINK => false,
				Codes::ATTR_LENGTH => 3,
			),
			array(
				Codes::ATTR_TAG => 'img',
				Codes::ATTR_TYPE => Codes::TYPE_UNPARSED_CONTENT,
				Codes::ATTR_CONTENT => '<img src="$1" alt="" class="bbc_img" />',
				Codes::ATTR_VALIDATE => self::bbcValidateImageNeedsCache(),
				Codes::ATTR_DISABLED_CONTENT => '($1)',
				Codes::ATTR_BLOCK_LEVEL => false,
				Codes::ATTR_AUTOLINK => false,
				Codes::ATTR_LENGTH => 3,
			)
		));
	}
}
