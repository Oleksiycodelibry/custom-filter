/* global CF_Data */
(function () {
	'use strict';

	var isAjax;
	var isAutosubmit;

	function getFilter() { return document.querySelector('.cf-filter'); }

	function init() {
		var filter = getFilter();
		if (!filter) return;

		// Wipe stale listeners by replacing the node with a deep clone.
		var clean = filter.cloneNode(true);
		filter.parentNode.replaceChild(clean, filter);
		filter = clean;

		var form = filter.querySelector('.cf-filter__form');
		isAutosubmit = !!+CF_Data.autosubmit;
		isAjax       = !!CF_Data.ajax_filter;

		// -------------------------------------------------------------------------
		// Dropdown open/close
		// -------------------------------------------------------------------------
		filter.addEventListener('click', function (e) {
			var label = e.target.closest('.cf-filter__label--dropdown');
			if (!label) return;
			toggleDropdown(label);
		});

		filter.addEventListener('keypress', function (e) {
			if (e.key !== 'Enter') return;
			var label = e.target.closest('.cf-filter__label--dropdown');
			if (!label) return;
			toggleDropdown(label);
		});

		function toggleDropdown(label) {
			var expanded = label.getAttribute('aria-expanded') === 'true';
			label.setAttribute('aria-expanded', String(!expanded));
			var wrap = label.closest('.cf-filter__block').querySelector('.cf-filter__list-wrap');
			if (wrap) slideToggle(wrap, 200);
		}

		// -------------------------------------------------------------------------
		// Checkbox / radio-visual / label inputs
		// -------------------------------------------------------------------------
		filter.addEventListener('change', function (e) {
			var input = e.target.closest('.cf-filter__input');
			if (!input) return;

			var labelItem  = input.closest('.cf-filter__label-item');
			var radioLabel = input.closest('.cf-filter__radio-label');

			if (labelItem)  labelItem.classList.toggle('is-active', input.checked);
			if (radioLabel) radioLabel.classList.toggle('is-checked', input.checked);

			var block = input.closest('.cf-filter__block');
			if (block && block.dataset.logic === 'single') {
				block.querySelectorAll('.cf-filter__input').forEach(function (other) {
					if (other === input) return;
					other.checked = false;
					var otherItem  = other.closest('.cf-filter__label-item');
					var otherRadio = other.closest('.cf-filter__radio-label');
					if (otherItem)  otherItem.classList.remove('is-active');
					if (otherRadio) otherRadio.classList.remove('is-checked');
				});
			}

			if (isAutosubmit) cfSubmit(form);
		});

		// -------------------------------------------------------------------------
		// Price range slider
		// -------------------------------------------------------------------------
		filter.querySelectorAll('.cf-filter__block--price[data-type="slider"]').forEach(function (block) {
			var min   = parseFloat(block.dataset.min);
			var max   = parseFloat(block.dataset.max);
			var rMin  = block.querySelector('.cf-price-slider__input--min');
			var rMax  = block.querySelector('.cf-price-slider__input--max');
			var hMin  = block.querySelector('.cf-price-hidden-min');
			var hMax  = block.querySelector('.cf-price-hidden-max');
			var lMin  = block.querySelector('.cf-price-slider__val--min');
			var lMax  = block.querySelector('.cf-price-slider__val--max');
			var range = block.querySelector('.cf-price-slider__range');

			function fmt(n) {
				var s = CF_Data.currency_symbol || '', p = CF_Data.currency_pos || 'left', v = Math.round(n);
				return ({ left: s+v, right: v+s, left_space: s+' '+v, right_space: v+' '+s })[p] || s+v;
			}

			function sync() {
				var lo = +rMin.value, hi = +rMax.value;
				range.style.left  = ((lo - min) / (max - min) * 100) + '%';
				range.style.right = ((1 - (hi - min) / (max - min)) * 100) + '%';
				if (lMin) lMin.textContent = fmt(lo);
				if (lMax) lMax.textContent = fmt(hi);
			}

			rMin.addEventListener('input', function () {
				if (+rMin.value >= +rMax.value) rMin.value = +rMax.value - 1;
				sync();
			});
			rMax.addEventListener('input', function () {
				if (+rMax.value <= +rMin.value) rMax.value = +rMin.value + 1;
				sync();
			});

			[rMin, rMax].forEach(function (r) {
				r.addEventListener('change', function () {
					hMin.disabled = false; hMin.value = rMin.value;
					hMax.disabled = false; hMax.value = rMax.value;
					cfSubmit(form);
				});
			});

			sync();
			if (hMin.value !== '') hMin.value = rMin.value;
			if (hMax.value !== '') hMax.value = rMax.value;
		});

		// -------------------------------------------------------------------------
		// Price text inputs
		// -------------------------------------------------------------------------
		var priceTimer;
		filter.addEventListener('keydown', function (e) {
			if (!e.target.closest('.cf-price-inputs__input')) return;
			if (e.key !== 'Enter') return;
			e.preventDefault();
			clearTimeout(priceTimer);
			cfSubmit(form);
		});
		filter.addEventListener('input', function (e) {
			if (!e.target.closest('.cf-price-inputs__input')) return;
			clearTimeout(priceTimer);
			priceTimer = setTimeout(function () { cfSubmit(form); }, 600);
		});
	}

	// -------------------------------------------------------------------------
	// Core submit
	// -------------------------------------------------------------------------
	function cfSubmit(form) {
		if (!isAjax) {
			form.submit();
			return;
		}
		var params   = new URLSearchParams(new FormData(form)).toString();
		var base     = (form.getAttribute('action') || CF_Data.shop_url).split('?')[0];
		var fetchUrl = base + (params ? '?' + params : '');
		history.pushState({ cfParams: params, cfBase: base }, '', fetchUrl);
		cfDoFetch(fetchUrl);
	}

	// -------------------------------------------------------------------------
	// AJAX fetch + region swap
	// -------------------------------------------------------------------------
	var PRODUCT_GRID_SELECTORS  = ['ul.products', '.wc-block-grid__products', '.products-grid'];
	var PAGINATION_SELECTORS    = ['.woocommerce-pagination', 'nav.woocommerce-pagination'];
	var ACTIVE_FILTERS_SELECTOR = '.cf-active-filters';
	var RESULT_COUNT_SELECTOR   = '.woocommerce-result-count';

	function cfDoFetch(url) {
		var grid = findFirst(PRODUCT_GRID_SELECTORS);
		if (grid) grid.classList.add('cf-ajax-loading');

		fetch(url, { headers: { 'X-CF-Ajax': '1' } })
		.then(function (r) {
			if (!r.ok) throw new Error('bad response');
			return r.text();
		})
		.then(function (html) {
			var doc = new DOMParser().parseFromString(html, 'text/html');
			swapRegions(doc);
			var newGrid = findFirst(PRODUCT_GRID_SELECTORS);
			if (newGrid) newGrid.classList.remove('cf-ajax-loading');
			document.body.dispatchEvent(new CustomEvent('cf_ajax_done', { detail: { url: url } }));
		})
		.catch(function () {
			window.location.href = url;
		});
	}

	function swapRegions(doc) {
		// Save open/closed state of every dropdown before replacing filter HTML.
		var dropdownStates = {};
		var filterBefore = getFilter();
		if (filterBefore) {
			filterBefore.querySelectorAll('.cf-filter__block').forEach(function (block) {
				var key  = block.dataset.taxonomy || block.dataset.param || '';
				if (!key) return;
				var label = block.querySelector('.cf-filter__label--dropdown');
				var wrap  = block.querySelector('.cf-filter__list-wrap');
				if (label) {
					dropdownStates[key] = {
						expanded: label.getAttribute('aria-expanded') === 'true',
						visible:  wrap ? wrap.style.display !== 'none' : false,
					};
				}
			});
		}

		swapOne(PRODUCT_GRID_SELECTORS, doc);
		swapOne(['.cf-filter'], doc);
		swapOne(PAGINATION_SELECTORS, doc, true);
		swapOne([ACTIVE_FILTERS_SELECTOR], doc, true);
		swapOne([RESULT_COUNT_SELECTOR], doc, true);

		// Restore dropdown open/closed state on the freshly swapped filter DOM.
		// Must happen BEFORE init() so the visible state is correct when
		// listeners are attached.
		var filterAfter = getFilter();
		if (filterAfter) {
			filterAfter.querySelectorAll('.cf-filter__block').forEach(function (block) {
				var key   = block.dataset.taxonomy || block.dataset.param || '';
				var state = dropdownStates[key];
				if (!state) return;
				var label = block.querySelector('.cf-filter__label--dropdown');
				var wrap  = block.querySelector('.cf-filter__list-wrap');
				if (label) label.setAttribute('aria-expanded', String(state.expanded));
				if (wrap)  wrap.style.display = state.visible ? '' : 'none';
			});
		}

		// init() clones the node to wipe stale listeners, then re-attaches fresh ones.
		init();
	}

	function swapOne(selectors, doc, optional) {
		var live    = findFirst(selectors);
		var fetched = findFirst(selectors, doc);
		if (!live) return;
		if (fetched) {
			live.innerHTML = fetched.innerHTML;
			live.className = fetched.className;
		} else if (optional) {
			live.innerHTML = '';
		}
	}

	function findFirst(selectors, root) {
		root = root || document;
		for (var i = 0; i < selectors.length; i++) {
			var el = root.querySelector(selectors[i]);
			if (el) return el;
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Active filters bar — intercept chip and "Clear all" clicks
	// -------------------------------------------------------------------------
	document.addEventListener('click', function (e) {
		if (!isAjax) return;
		var chip = e.target.closest('.cf-active-filters__chip, .cf-active-filters__clear');
		if (!chip) return;
		e.preventDefault();
		var url = chip.getAttribute('href');
		if (!url) return;
		history.pushState({ cfParams: url.split('?')[1] || '', cfBase: url.split('?')[0] }, '', url);
		cfDoFetch(url);
	});

	// -------------------------------------------------------------------------
	// Browser back / forward
	// -------------------------------------------------------------------------
	window.addEventListener('popstate', function () {
		if (!isAjax) return;
		cfDoFetch(window.location.href);
	});

	// -------------------------------------------------------------------------
	// Lightweight slideToggle
	// -------------------------------------------------------------------------
	function slideToggle(el, duration) {
		if (el.style.display === 'none' || getComputedStyle(el).display === 'none') {
			slideDown(el, duration);
		} else {
			slideUp(el, duration);
		}
	}
	function slideDown(el, duration) {
		el.style.display    = '';
		el.style.overflow   = 'hidden';
		el.style.height     = '0';
		var target = el.scrollHeight;
		el.style.transition = 'height ' + duration + 'ms ease';
		void el.offsetHeight;
		el.style.height = target + 'px';
		el.addEventListener('transitionend', function done() {
			el.style.height = el.style.overflow = el.style.transition = '';
			el.removeEventListener('transitionend', done);
		});
	}
	function slideUp(el, duration) {
		el.style.overflow   = 'hidden';
		el.style.height     = el.scrollHeight + 'px';
		el.style.transition = 'height ' + duration + 'ms ease';
		void el.offsetHeight;
		el.style.height = '0';
		el.addEventListener('transitionend', function done() {
			el.style.display = 'none';
			el.style.height  = el.style.overflow = el.style.transition = '';
			el.removeEventListener('transitionend', done);
		});
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------
	init();

}());

// ---------------------------------------------------------------------------
// Allow deselecting an active price range radio by clicking it again.
// ---------------------------------------------------------------------------
document.addEventListener('click', function (e) {
	var radio = e.target.closest('input[type="radio"].cf-filter__input');
	if (!radio) return;
	if (radio.dataset.wasChecked === 'true') {
		radio.checked            = false;
		radio.dataset.wasChecked = 'false';
		var label = radio.closest('.cf-filter__radio-label');
		if (label) label.classList.remove('is-checked');
		var formEl = radio.closest('form');
		if (formEl) formEl.submit();
	} else {
		radio.dataset.wasChecked = 'true';
	}
});
document.addEventListener('mousedown', function (e) {
	var radio = e.target.closest('input[type="radio"].cf-filter__input');
	if (!radio) return;
	radio.dataset.wasChecked = radio.checked ? 'true' : 'false';
});