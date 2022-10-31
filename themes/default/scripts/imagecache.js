/**
 * Provides a simple image cache, intended for serving https images over https
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2022 Spuds
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

			/*
			// This will fetch the image as a dataURL and change the src to the data
			(async function() {
				let blob = await fetch($img.attr('src')).then(r => r.blob());
				let dataUrl = await new Promise(resolve => {
					let reader = new FileReader();
					reader.onload = () => resolve(reader.result);
					reader.readAsDataURL(blob);
				});

				$img.attr('src', dataUrl);
			})();*/
		}
	});
});
