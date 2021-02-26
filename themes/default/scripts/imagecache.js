/**
 * Provides a simple image cache, intended for serving https images over https
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2017 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.1
 *
 */

$(function() {
	$('.bbc_img').each(function () {
		let $img = $(this),
			relattr = $img.attr('rel');

		if (relattr === 'cached') {
			let $a = $('<span class="cached_img"></span>'),
				$warn = $('<span class="infobox"><a class="external"></a></span>');

			$warn.find('.external').attr('target', '_blank').attr('href', $img.data('url')).text($img.data('warn'));
			$img.wrap($a);
			$img.after($warn);
		}
	});
});
