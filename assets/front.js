(function () {
	'use strict';
	function closeAll(except) {
		document.querySelectorAll('.promeng-popup.open').forEach(function (p) {
			if (p !== except) { p.classList.remove('open'); }
		});
	}
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.promeng-info') : null;
		if (btn) {
			e.preventDefault();
			e.stopPropagation();
			var cell = btn.closest('th') || btn.closest('tr');
			var pop = cell ? cell.parentNode.querySelector('.promeng-popup') : null;
			if (!pop) { pop = (btn.closest('tr') || document).querySelector('.promeng-popup'); }
			if (pop) {
				closeAll(pop);
				pop.classList.toggle('open');
			}
			return;
		}
		if (!e.target.closest || !e.target.closest('.promeng-popup')) {
			closeAll(null);
		}
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { closeAll(null); }
	});
})();
