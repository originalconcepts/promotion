/* global jQuery */
(function ($) {
	'use strict';
	$(function () {
		// Enhanced selects (categories + WooCommerce product search).
		if ($.fn.selectWoo) {
			$('.wc-enhanced-select').selectWoo();
		}

		// Influencer user search (mirrors WooCommerce customer search).
		if ($.fn.selectWoo && typeof wc_enhanced_select_params !== 'undefined') {
			$('.wc-customer-search').selectWoo({
				ajax: {
					url: wc_enhanced_select_params.ajax_url,
					dataType: 'json',
					delay: 250,
					data: function (params) {
						return {
							term: params.term,
							action: 'woocommerce_json_search_customers',
							security: wc_enhanced_select_params.search_customers_nonce
						};
					},
					processResults: function (data) {
						var terms = [];
						if (data) { $.each(data, function (id, text) { terms.push({ id: id, text: text }); }); }
						return { results: terms };
					},
					cache: true
				},
				minimumInputLength: 1,
				allowClear: true
			});
		}

		// Coupon code field toggle.
		$('#promeng-requires-coupon').on('change', function () {
			$('.promeng-coupon-field').toggle(this.checked);
		});

		// Buy-condition fields toggle.
		$('#promeng-has-condition').on('change', function () {
			$('.promeng-conditions').toggle(this.checked);
			if (!this.checked) {
				$('.promeng-conditions input').val('');
			}
		});

		// Limits toggle (packs Limit fields; off by default).
		$('#promeng-has-limits').on('change', function () {
			$('.promeng-limits').toggle(this.checked);
			if (!this.checked) {
				$('.promeng-limits input').val('');
			}
		});

		// Targeting fields depend on "applies_to".
		function syncTargets() {
			var val = $('#promeng-applies-to').val();
			$('.promeng-target').hide();
			if (val === 'products') {
				$('.promeng-target-products').show();
			} else if (val === 'categories') {
				$('.promeng-target-categories').show();
				$('.promeng-target-excluded').show();
			} else if (val === 'all') {
				$('.promeng-target-excluded').show();
			}
			// 'cart' shows no targeting fields.
		}
		$('#promeng-applies-to').on('change', syncTargets);
		syncTargets();
	});
})(jQuery);

/* Buy X Pay Y: scope -> product/category targets */
(function ($) {
	$(function () {
		function syncScope() {
			var v = $('#promeng-scope').val();
			$('.promeng-target').hide();
			if (v === 'product') {
				$('.promeng-target-products').show();
			} else {
				$('.promeng-target-categories').show();
				$('.promeng-target-excluded').show();
			}
		}
		if ($('#promeng-scope').length) {
			$('#promeng-scope').on('change', syncScope);
			syncScope();
		}

		/* Buy X Get Y: buy target type */
		function syncBuyApplies() {
			var v = $('#promeng-buy-applies').val();
			$('.promeng-buy-target').hide();
			if (v === 'products') {
				$('.promeng-buy-products').show();
			} else if (v === 'categories') {
				$('.promeng-buy-categories').show();
				$('.promeng-buy-excluded').show();
			} else {
				$('.promeng-buy-excluded').show();
			}
		}
		if ($('#promeng-buy-applies').length) {
			$('#promeng-buy-applies').on('change', syncBuyApplies);
			syncBuyApplies();
		}

		/* Buy X Get Y: benefit target type + auto-add visibility */
		function syncAutoAdd() {
			var free = $('#promeng-benefit-type').val() === 'free';
			var prod = $('#promeng-benefit-applies').val() === 'products';
			$('#promeng-auto-add-row').toggle(free && prod);
		}
		function syncBenefitApplies() {
			var v = $('#promeng-benefit-applies').val();
			$('.promeng-benefit-target').hide();
			if (v === 'products') {
				$('.promeng-benefit-products').show();
			} else if (v === 'categories') {
				$('.promeng-benefit-categories').show();
				$('.promeng-benefit-excluded').show();
			} else {
				$('.promeng-benefit-excluded').show(); // same / all
			}
			$('.promeng-benefit-same-hint').toggle(v === 'same');
			syncAutoAdd();
		}
		if ($('#promeng-benefit-applies').length) {
			$('#promeng-benefit-applies').on('change', syncBenefitApplies);
			syncBenefitApplies();
		}

		/* Buy X Get Y: benefit type -> value field + auto-add row */
		$('#promeng-benefit-type').on('change', function () {
			$('#promeng-benefit-value').toggle(this.value !== 'free');
			syncAutoAdd();
		});

		/* ---- Live promotion summary (left sticky panel) ---- */
		var S = window.pengSum || {};
		var $sum = $('.promeng-side-summary');

		function spf(tpl) {
			var args = Array.prototype.slice.call(arguments, 1);
			var i = 0;
			return String(tpl || '')
				.replace(/%(\d+)\$[sd]/g, function (m, n) { return args[n - 1] != null ? args[n - 1] : ''; })
				.replace(/%[sd]/g, function () { return args[i++] != null ? args[i - 1] : ''; })
				.replace(/%%/g, '%');
		}
		function fld(name) { return $('.promeng-form [name="' + name + '"]'); }
		function val(name) {
			var $e = fld(name);
			if (!$e.length) { return ''; }
			if ($e.is(':checkbox')) { return $e.is(':checked'); }
			return $e.val();
		}
		function num(name) { var x = parseFloat(val(name)); return isNaN(x) ? 0 : x; }
		function fmt(x) { return (Math.round(x * 100) / 100).toString(); }
		function money(x) { return fmt(x) + ' ' + (S.currency || '\u20aa'); }
		function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
		function cleanName(s) { return String(s == null ? '' : s).replace(/\s*\(#\d+\)\s*$/, '').trim(); }
		function picked(name) {
			var out = [];
			$('.promeng-form [name="' + name + '[]"] option:selected').each(function () {
				var t = $(this).text().trim();
				if (t) { out.push(cleanName(t)); }
			});
			return out;
		}
		// First name + a "+N" chip (hover shows the rest) so the deal stays short.
		function chip(names) {
			names = names.filter(Boolean);
			if (!names.length) { return ''; }
			if (names.length === 1) { return esc(names[0]); }
			return esc(names[0]) + ' <span class="promeng-sum-more" title="' + esc(names.slice(1).join(', ')) + '">+' + (names.length - 1) + '</span>';
		}
		function scopeChip(applies, prodField, catField) {
			if ('cart' === applies) { return esc(S.scope_cart); }
			if ('products' === applies || 'product' === applies) {
				var p = picked(prodField);
				return p.length ? chip(p) : esc(S.scope_products);
			}
			if ('categories' === applies || 'category' === applies) {
				var c = picked(catField);
				return c.length ? chip(c) : esc(spf(S.scope_cats, '\u2026'));
			}
			return esc(S.scope_all);
		}

		function buildSummary() {
			if (!$sum.length) { return; }
			var type = $sum.data('type');
			var deal = '';
			var lines = [];

			if ('discount' === type) {
				var dv = num('discount_value');
				var dt = val('discount_type');
				var sc = scopeChip(val('applies_to'), 'product_ids', 'category_ids');
				if (dv > 0) {
					deal = ('fixed' === dt)
						? spf(S.amount_off, esc(money(dv)), sc)
						: spf(S.percent_off, esc(fmt(dv)), sc);
				}
				// Purchase conditions.
				var cAmt = num('condition_min_amount');
				if (cAmt > 0) { lines.push({ ic: 'dashicons-cart', t: spf(S.cond_amount, money(cAmt)) }); }
				var cItems = parseInt(val('condition_min_items') || '0', 10);
				if (cItems > 0) { lines.push({ ic: 'dashicons-cart', t: spf(S.cond_items, cItems) }); }
				var liItems = parseInt(val('limit_discounted_items') || '0', 10);
				if (liItems > 0) { lines.push({ ic: 'dashicons-tag', t: spf(S.limit_items, liItems) }); }
			} else if ('buy_x_pay_y' === type) {
				var bq = num('buy_quantity');
				var pa = num('pay_amount');
				var bsc = scopeChip(val('scope'), 'product_ids', 'category_ids');
				if (bq > 0 && pa > 0) {
					deal = spf(S.bxpy_deal, esc(fmt(bq)), bsc, esc(money(pa)));
				}
			} else if ('buy_x_get_y' === type) {
				var bt = val('benefit_type') || 'free';
				var benefit = S.benefit_free;
				if ('fixed' === bt) { benefit = spf(S.benefit_fixed, money(num('benefit_value'))); }
				else if ('percent' === bt) { benefit = spf(S.benefit_percent, fmt(num('benefit_value'))); }

				var bq2 = Math.max(1, parseInt(val('benefit_quantity') || '1', 10));
				var benApplies = val('benefit_applies_to') || 'products';
				var benScope;
				if ('categories' === benApplies) {
					benScope = esc(String(bq2) + ' ') + (chip(picked('benefit_category_ids')) || esc(spf(S.scope_cats, '\u2026')));
				} else if ('all' === benApplies) {
					benScope = esc(spf(S.items, bq2));
				} else if ('same' === benApplies) {
					benScope = esc(String(bq2) + ' ') + scopeChip(val('buy_applies_to'), 'buy_product_ids', 'buy_category_ids');
				} else {
					benScope = esc(String(bq2) + ' ') + (chip(picked('benefit_product_ids')) || esc(S.a_product));
				}
				var getPart = benScope + ' ' + esc(benefit);

				var amt = num('buy_min_amount');
				var minItems = Math.max(1, parseInt(val('buy_min_items') || '1', 10));
				var buyApplies = val('buy_applies_to');
				var specific = ('products' === buyApplies || 'product' === buyApplies || 'categories' === buyApplies || 'category' === buyApplies);
				var buyPart;
				if (specific) {
					buyPart = esc(String(minItems) + ' ') + scopeChip(buyApplies, 'buy_product_ids', 'buy_category_ids');
				} else if (amt > 0) {
					buyPart = esc(money(amt));
				} else {
					buyPart = esc(spf(S.items, minItems));
				}
				deal = spf(S.bxgy_deal_items, buyPart, getPart);

				// Minimum cart amount as a separate condition (when a product/category is the focus).
				if (specific && amt > 0) {
					lines.push({ ic: 'dashicons-cart', t: spf(S.cond_amount, money(amt)) });
				}
				// Auto-added free gift (only for a specific benefit product).
				if ('free' === bt && 'products' === benApplies && val('auto_add')) {
					lines.push({ ic: 'dashicons-cart', cls: 'pos', t: S.autoadd });
				}
				// Allocation mode note (legal distribution is the default).
				if (val('apply_to_cheapest')) {
					lines.push({ ic: 'dashicons-tag', t: S.cheapest_note });
				}
			}

			// Exclusions.
			var exField = ('buy_x_get_y' === type) ? 'buy_excluded_product_ids' : 'excluded_product_ids';
			var ex = picked(exField);
			if (ex.length) { lines.push({ ic: 'dashicons-dismiss', t: spf(S.excludes, ex.join(', ')) }); }

			// Coupon.
			if (val('requires_coupon') && (val('coupon_code') || '').trim()) {
				lines.push({ ic: 'dashicons-tickets-alt', coupon: val('coupon_code').trim() });
			}

			// Schedule.
			var starts = val('starts_at'), ends = val('ends_at');
			var expired = false;
			if (ends) {
				var endD = new Date(ends);
				if (!isNaN(endD.getTime()) && endD < new Date(new Date().toDateString())) { expired = true; }
			}
			var sched;
			if (expired) {
				sched = spf(S.sched_ended, ends);
			} else if (starts && ends) { sched = spf(S.sched_range, starts, ends); }
			else if (ends) { sched = spf(S.sched_until, ends); }
			else if (starts) { sched = spf(S.sched_from, starts); }
			else { sched = S.sched_open; }
			var dnums = [];
			$('.promeng-form [name="weekdays[]"]:checked').each(function () { dnums.push(parseInt(this.value, 10)); });
			if (!expired && dnums.length && dnums.length < 7 && S.days) {
				sched += ' \u00b7 ' + spf(S.sched_days, dnums.map(function (n) { return S.days[n]; }).join(', '));
			}
			lines.push({ ic: expired ? 'dashicons-warning' : 'dashicons-calendar-alt', cls: expired ? 'neg' : '', t: sched });

			// Per-order limit.
			var lpo = parseInt(val('limit_per_order') || '0', 10);
			if (lpo > 0) { lines.push({ ic: 'dashicons-cart', t: spf(S.per_order, lpo) }); }

			// Per-customer limit.
			var lpc = parseInt(val('limit_per_customer') || '0', 10);
			if (lpc === 1) { lines.push({ ic: 'dashicons-admin-users', t: S.limit_one }); }
			else if (lpc > 1) { lines.push({ ic: 'dashicons-admin-users', t: spf(S.limit_n, lpc) }); }

			// Audience (customer type). Shown only when narrowed to registered/guest.
			var ctype = $('.promeng-form input[name="customer_type"]:checked').val() || 'all';
			if ('registered' === ctype && S.audience_registered) {
				lines.push({ ic: 'dashicons-admin-users', t: S.audience_registered });
			} else if ('guest' === ctype && S.audience_guest) {
				lines.push({ ic: 'dashicons-admin-users', t: S.audience_guest });
			}

			// Platform (only when the app integration is active, i.e. there are
			// real channel checkboxes). Reflects the actual web/app selection.
			var chanInputs = $('.promeng-form input[name="channels[]"]');
			if (chanInputs.filter('[type="checkbox"]').length) {
				var selWeb = chanInputs.filter('[value="web"]:checked').length > 0;
				var selApp = chanInputs.filter('[value="app"]:checked').length > 0;
				var chanText = '';
				if (selWeb && selApp) { chanText = S.channel_both; }
				else if (selApp) { chanText = S.channel_app; }
				else if (selWeb) { chanText = S.channel_web; }
				if (chanText) {
					lines.push({ ic: selApp && !selWeb ? 'dashicons-smartphone' : 'dashicons-admin-site-alt3', t: chanText });
				}
			}

			// Render (deal can contain a "+N" chip, so use html()).
			$sum.find('.promeng-sum-deal').html(deal || esc(S.placeholder));
			$sum.find('.promeng-sum-headline').toggleClass('is-empty', !deal);
			var html = '';
			lines.forEach(function (l) {
				html += '<li class="' + (l.cls || '') + '"><span class="promeng-sum-ic dashicons ' + l.ic + (l.cls ? ' ' + l.cls : '') + '"></span>';
				if (l.coupon) {
					html += '<span>' + esc(S.coupon) + ' <span class="promeng-sum-pill">' + esc(l.coupon) + '</span></span>';
				} else {
					html += '<span>' + esc(l.t) + '</span>';
				}
				html += '</li>';
			});
			$sum.find('.promeng-summary-lines').html(html);
		}

		if ($sum.length) {
			$('.promeng-form').on('input change keyup', buildSummary);
			$(document).on('change', '.wc-enhanced-select', buildSummary);
			buildSummary();
		}
	});
})(jQuery);
