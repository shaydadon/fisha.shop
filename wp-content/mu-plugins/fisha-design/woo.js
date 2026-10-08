/* Fisha — WooCommerce: size/option buttons instead of dropdowns on product pages; sticky offset for the summary column */
jQuery(function ($) {
	var h = document.querySelector('.wp-site-blocks > header');
	if (h) document.documentElement.style.setProperty('--fisha-header-h', h.getBoundingClientRect().height + 'px');

	$('form.variations_form').each(function () {
		var $form = $(this);
		$form.find('table.variations select').each(function () {
			var $sel = $(this), name = $sel.closest('tr').find('th label').text().trim() || 'Option';
			var $wrap = $('<div class="fs-opts" role="group"></div>').attr('aria-label', name);
			$sel.find('option').each(function () {
				if (!this.value) return;
				$('<button type="button" class="fs-opt"></button>').text(this.text.replace(/^[\s"“”']+|[\s"“”']+$/g, '')).attr({ 'data-value': this.value, 'aria-pressed': 'false' }).appendTo($wrap);
			});
			$sel.addClass('fs-hidden-select').attr({ 'aria-hidden': 'true', tabindex: '-1' }).before($wrap);
			$wrap.on('click', '.fs-opt', function () {
				var v = $(this).data('value') + '';
				$sel.val($sel.val() === v ? '' : v).trigger('change');
			});
			function sync() {
				var cur = $sel.val();
				$wrap.find('.fs-opt').each(function () {
					var v = $(this).data('value') + '', $o = $sel.find('option').filter(function () { return this.value === v; });
					$(this).attr('aria-pressed', v === cur ? 'true' : 'false').prop('disabled', !$o.length || $o.prop('disabled'));
				});
			}
			$sel.on('change', sync);
			$form.on('woocommerce_update_variation_values reset_data', function () { setTimeout(sync, 0); });
			sync();
		});
	});
});
