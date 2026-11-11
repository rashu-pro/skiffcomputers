/**
 * 11.11 Mega Sale page + [skiff_1111_countdown] shortcode.
 *
 * - Countdown: driven by UTC timestamps in data attributes and the visitor's clock, so a page
 *   served from LiteSpeed / WP Super Cache never shows a frozen timer. If the cached HTML was
 *   rendered in an older campaign state (e.g. before the start), the grid is re-fetched.
 * - Category switching + load more over admin-ajax (action skiff_1111_products), with skeletons,
 *   and history.replaceState(?cat=slug) so category URLs are shareable (the server renders the
 *   same category on first load).
 * - Cards have no add-to-cart button; the whole card links to the product page.
 *
 * No jQuery dependency.
 */
(function () {
	'use strict';

	var cfg = window.skiff1111 || {};
	var i18n = cfg.i18n || {};
	var BN_DIGITS = '০১২৩৪৫৬৭৮৯';

	function bn(str) {
		return String(str).replace(/\d/g, function (d) {
			return BN_DIGITS.charAt(+d);
		});
	}

	function pad(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function stateAt(start, end, now) {
		if (start && now < start) {
			return 'upcoming';
		}
		if (end && now > end) {
			return 'ended';
		}
		return 'running';
	}

	/* ------------------------------------------------------------------ *
	 * Countdown
	 * ------------------------------------------------------------------ */

	var countdowns = Array.prototype.slice.call(document.querySelectorAll('[data-skiff-countdown]'));

	function setUnit(el, unit, value) {
		var node = el.querySelector('[data-unit="' + unit + '"]');
		var text = bn(pad(value));
		if (node && node.textContent !== text) {
			node.textContent = text;
		}
	}

	function tick() {
		var now = Math.floor(Date.now() / 1000);

		countdowns.forEach(function (el) {
			var start = parseInt(el.getAttribute('data-start'), 10) || 0;
			var end = parseInt(el.getAttribute('data-end'), 10) || 0;
			var state = stateAt(start, end, now);
			var live = el.querySelector('.skiff-1111-countdown__live');
			var ended = el.querySelector('[data-role="ended"]');
			var label = el.querySelector('[data-role="label"]');

			if (state !== el.getAttribute('data-status')) {
				el.setAttribute('data-status', state);
				el.dispatchEvent(new CustomEvent('skiff1111:status', { bubbles: true, detail: { status: state } }));
			}

			if (state === 'ended') {
				if (live) {
					live.hidden = true;
				}
				if (ended) {
					ended.hidden = false;
				}
				if (el.hasAttribute('data-hide-on-end')) {
					el.hidden = true;
				}
				return;
			}

			if (live) {
				live.hidden = false;
			}
			if (ended) {
				ended.hidden = true;
			}
			if (label && i18n[state] && label.textContent !== i18n[state]) {
				label.textContent = i18n[state];
			}

			var diff = Math.max(0, (state === 'upcoming' ? start : end) - now);
			setUnit(el, 'd', Math.floor(diff / 86400));
			setUnit(el, 'h', Math.floor((diff % 86400) / 3600));
			setUnit(el, 'm', Math.floor((diff % 3600) / 60));
			setUnit(el, 's', diff % 60);
		});
	}

	/* ------------------------------------------------------------------ *
	 * Offer page grid
	 * ------------------------------------------------------------------ */

	var root = document.querySelector('[data-skiff-1111]');

	if (root) {
		var grid = root.querySelector('[data-role="grid"]');
		var moreBtn = root.querySelector('[data-role="more"]');
		var titleEl = root.querySelector('[data-role="title"]');
		var subtitleEl = root.querySelector('[data-role="subtitle"]');
		var mainEl = root.querySelector('.skiff-1111__main');
		var controller = null;

		var skeleton =
			'<div class="skiff-1111-card skiff-1111-card--skeleton" aria-hidden="true">' +
			'<div class="skiff-1111-card__media"></div>' +
			'<div class="skiff-1111-skel-line"></div>' +
			'<div class="skiff-1111-skel-line skiff-1111-skel-line--short"></div>' +
			'</div>';

		var skeletons = function (count) {
			var html = '';
			for (var i = 0; i < count; i++) {
				html += skeleton;
			}
			return html;
		};

		var removeSkeletons = function () {
			Array.prototype.forEach.call(grid.querySelectorAll('.skiff-1111-card--skeleton'), function (n) {
				n.parentNode.removeChild(n);
			});
		};

		var categoryUrl = function (cat) {
			var url = new URL(root.getAttribute('data-page-url'), window.location.href);
			if (cat) {
				url.searchParams.set('cat', cat);
			}
			return url.toString();
		};

		var setActive = function (cat) {
			Array.prototype.forEach.call(root.querySelectorAll('.skiff-1111-cats__link'), function (link) {
				var on = link.getAttribute('data-cat') === cat;
				link.classList.toggle('is-active', on);
				if (on) {
					link.setAttribute('aria-current', 'true');
					// Mobile chip bar: bring the chosen chip into view without moving the page.
					var list = link.closest('.skiff-1111-cats__list');
					if (list && list.scrollWidth > list.clientWidth) {
						list.scrollTo({ left: link.offsetLeft - 16, behavior: 'smooth' });
					}
				} else {
					link.removeAttribute('aria-current');
				}
			});
		};

		var load = function (cat, page, append) {
			if (controller) {
				controller.abort();
			}
			controller = typeof AbortController !== 'undefined' ? new AbortController() : null;

			root.classList.add('is-loading');
			grid.setAttribute('aria-busy', 'true');
			moreBtn.disabled = true;
			if (append) {
				grid.insertAdjacentHTML('beforeend', skeletons(4));
			} else {
				grid.innerHTML = skeletons(8);
				moreBtn.hidden = true;
			}

			var body = new URLSearchParams();
			body.append('action', 'skiff_1111_products');
			body.append('cat', cat);
			body.append('page', String(page));
			if (cfg.nonce) {
				body.append('nonce', cfg.nonce);
			}

			return fetch(cfg.ajaxUrl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
				signal: controller ? controller.signal : undefined
			})
				.then(function (res) {
					return res.json();
				})
				.then(function (json) {
					if (!json || !json.success) {
						throw new Error('bad response');
					}
					var data = json.data;
					removeSkeletons();
					if (append) {
						grid.insertAdjacentHTML('beforeend', data.html);
					} else {
						grid.innerHTML = data.html;
					}
					root.setAttribute('data-cat', cat);
					root.setAttribute('data-page', String(page));
					titleEl.textContent = data.title;
					subtitleEl.textContent = data.subtitle;
					moreBtn.hidden = !data.has_more;
				})
				.catch(function (err) {
					if (err && err.name === 'AbortError') {
						return;
					}
					removeSkeletons();
					if (!append) {
						grid.innerHTML = '';
					}
					grid.insertAdjacentHTML(
						'beforeend',
						'<p class="skiff-1111__empty skiff-1111__error">' + (i18n.error || 'Error') + '</p>'
					);
					moreBtn.hidden = append ? false : true;
				})
				.then(function () {
					root.classList.remove('is-loading');
					grid.setAttribute('aria-busy', 'false');
					moreBtn.disabled = false;
				});
		};

		// Category switching.
		root.addEventListener('click', function (e) {
			var link = e.target.closest('.skiff-1111-cats__link');
			if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) {
				return;
			}
			e.preventDefault();
			var cat = link.getAttribute('data-cat') || '';
			setActive(cat);
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', categoryUrl(cat));
			}
			// If the user has scrolled past the grid header, bring it back into view.
			if (mainEl && mainEl.getBoundingClientRect().top < 0) {
				window.scrollTo({ top: window.pageYOffset + mainEl.getBoundingClientRect().top - 140, behavior: 'smooth' });
			}
			load(cat, 1, false);
		});

		// Server-rendered ?cat=slug: bring the active chip into view in the mobile chip bar.
		setActive(root.getAttribute('data-cat') || '');

		// Load more.
		moreBtn.addEventListener('click', function () {
			var page = (parseInt(root.getAttribute('data-page'), 10) || 1) + 1;
			load(root.getAttribute('data-cat') || '', page, true);
		});

		// Campaign started / ended while the page was open, or the cached HTML is from an older state.
		root.addEventListener('skiff1111:status', function (e) {
			if (e.detail.status === 'ended' && root.getAttribute('data-after-end') === 'redirect' && !cfg.preview) {
				window.location.href = root.getAttribute('data-shop-url');
				return;
			}
			load(root.getAttribute('data-cat') || '', 1, false);
		});
	}

	if (countdowns.length) {
		tick();
		window.setInterval(tick, 1000);
	}
})();
