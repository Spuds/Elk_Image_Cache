/**
 * Provides a simple image cache, intended for serving http images over https
 *
 * @package ImageCache
 * @author Spuds
 * @copyright (c) 2022-2025 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 2.0.0
 *
 */

document.addEventListener('DOMContentLoaded', function () {
	document.querySelectorAll('.bbc_img').forEach(function (img) {
		const relattr = img.getAttribute('rel');

		// If the image is cached, wrap it in a span and add a info block
		if (relattr === 'cached') {
			const wrapper = document.createElement('span');
			wrapper.className = 'cached_img';

			const warn = document.createElement('span');
			//warn.className = 'infobox';

			// Done as button and eventlisten to avoid fancybox conflicts
			let link = document.createElement('button');
			link.className = 'linklevel1';
			link.setAttribute('style', 'font-size: var(--font12);margin: 0 auto');
			link.setAttribute('role', 'link');
			link.addEventListener('click', (e) => {
				e.preventDefault();
				window.open(img.dataset.url, '_blank', 'noopener,noreferrer');
			});
			link.textContent = img.dataset.warn;
			warn.appendChild(link);

			// Wrap the image
			if (img.parentNode) {
				img.parentNode.insertBefore(wrapper, img);
				wrapper.appendChild(img);
			}

			// Insert the warning element right after the image (inside the wrapper)
			img.insertAdjacentElement('afterend', warn);

			/*
			// This will fetch the image as a dataURL and change the src to the data
			(async function() {
			  var blob = await fetch(img.getAttribute('src')).then(function (r) { return r.blob(); });
			  var dataUrl = await new Promise(function (resolve) {
				var reader = new FileReader();
				reader.onload = function () { resolve(reader.result); };
				reader.readAsDataURL(blob);
			  });

			  img.setAttribute('src', dataUrl);
			})();
			*/
		}
	});
});

