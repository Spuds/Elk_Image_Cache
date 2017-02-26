<?php
// Version: 1.0.0; image cache

$txt['image_cache_warn_ext'] = 'External image, click here to view original';
$txt['image_cache_title'] = 'Image Cache Settings';
$txt['image_cache_label'] = 'Image Cache';
$txt['image_cache_enabled'] = 'Enable [img] ImageCache';
$txt['image_cache_keep_days'] = 'Remove cached images that have not been accessed in:';
$txt['image_cache_keep_days_subnote'] = 'Enter 0 to keep images indefinitely';
$txt['image_cache_all'] = 'Cache all [img]\'s, not just ones needed for HTTPS sites.';

$txt['image_cache_desc'] = 'This will serve images embedded with [IMG] tags from your domain through a proxy mechanism.  The remote image is saved to your cache directory and served from there.  You can choose to do this for all [IMG] tags or just those that would cause "insecure content" warnings when your site is running HTTPS';
$txt['image_cache_settings_description'] = 'Here you can set all settings involving the image cache and proxy.';

$txt['maintain_imagecache'] = 'Empty the image cache';
$txt['maintain_imagecache_info'] = 'This function will EMPTY the image cache should you need it to be cleared.';

$txt['core_settings_item_ic'] = 'Image Cache & Proxy';
$txt['core_settings_item_ic_desc'] = 'This will serve images embedded with [IMG] tags from your domain through a proxy mechanism.  The remote image is saved to your cache directory and served from there.  You can choose to do this for all [IMG] tags or just those that would cause "insecure content" warnings when your site is running HTTPS';

$txt['scheduled_task_remove_old_image_cache'] = 'Remove old image cache files';
$txt['scheduled_task_desc_remove_old_image_cache'] = 'Deletes files older than the number of days defined in the image cache settings in the admin panel.';
