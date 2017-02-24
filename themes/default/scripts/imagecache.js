/**
 * Provides a simple image cache, intended for serving https images over https
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 beta 4
 *
 */

$(function() {
	$('.bbc_img').each(function () {
		var $img = $(this),
			relattr = $img.attr('rel');

		if (relattr === 'cached') {
			var $a = $('<span class="cached_img"></span>'),
				$warn = $('<span class="infobox"><a class="external"></a></span>');

			$warn.find('.external').attr('target', '_blank').attr('href', $img.data('url')).text($img.data('warn'));
			$img.wrap($a);
			$img.after($warn);
		}
	});
});