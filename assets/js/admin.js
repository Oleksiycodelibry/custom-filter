/* global CF_Admin, jQuery */
/**
 * admin.js — filter row management in the Taxonomies tab
 *
 * Field visibility is driven by three CSS classes on .cf-field elements:
 *   .cf-field--price-only    visible only when taxonomy === '_price'
 *   .cf-field--taxonomy-only visible only when taxonomy !== '_price'
 *   .cf-field--dropdown-only visible only when taxonomy-only AND display type === 'dropdown'
 *
 * To add fields for a new filter type: add the appropriate modifier class to
 * the <div class="cf-field"> in tab-taxonomies.php, then extend toggleFields()
 * with a new condition.
 */
(function ($) {
	'use strict';

	// After any structural change (add/remove/sort), re-stamp array indexes
	// on data-index and all [name] attributes so PHP receives a clean 0-based array.
	function reindexRows() {
		$('#cf-filters-list .cf-filter-row').each(function (i) {
			$(this).attr('data-index', i)
				.find('[name]').each(function () {
					$(this).attr('name', $(this).attr('name').replace(/cf_filters\[\d+\]/, 'cf_filters[' + i + ']'));
				});
		});
	}

	function toggleFields($row) {
		var isPrice   = $row.find('.cf-taxonomy-select').val() === '_price';
		var isDropdown = $row.find('.cf-label-type-select').val() === 'dropdown';
		$row.find('.cf-field--price-only').toggle(isPrice);
		$row.find('.cf-field--taxonomy-only').toggle(!isPrice);
		$row.find('.cf-field--dropdown-only').toggle(!isPrice && isDropdown);
	}

	function bindRow($row) {
		$row.find('.cf-taxonomy-select').on('change', function () {
			$row.find('.cf-filter-row__title').text($(this).find('option:selected').text().trim() || 'New Filter');
			toggleFields($row);
		});
		$row.find('.cf-label-type-select').on('change', function () { toggleFields($row); });
		$row.find('.cf-filter-row__toggle').on('click', function () {
			var $body = $row.find('.cf-filter-row__body');
			$body.toggle();
			$(this).text($body.is(':visible') ? '▲' : '▼');
		});
		$row.find('.cf-filter-row__remove').on('click', function () {
			if (confirm('Remove this filter?')) { $row.remove(); reindexRows(); }
		});
		toggleFields($row);
	}

	$('#cf-add-filter').on('click', function () {
		var $list   = $('#cf-filters-list');
		var $newRow = $( document.getElementById('cf-filter-row-template').innerHTML.replace(/__INDEX__/g, $list.find('.cf-filter-row').length) );
		$list.append($newRow);
		bindRow($newRow);
		reindexRows();
	});

	$('#cf-filters-list .cf-filter-row').each(function () { bindRow($(this)); });

	if ($.fn.sortable) {
		$('#cf-filters-list').sortable({
			handle: '.cf-filter-row__drag',
			placeholder: 'cf-filter-row cf-filter-row--placeholder',
			stop: reindexRows,
		});
	}

}(jQuery));