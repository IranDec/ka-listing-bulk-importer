/*! KA Listing Bulk Importer 4 - admin. @author Mohammad Babaei | https://adschi.com */
(function () {
	'use strict';
	var cfg = window.kaLbi || { i18n: {} };
	var t = cfg.i18n;
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			[].concat(data[k]).forEach(function (v) { body.append(Array.isArray(data[k]) ? k + '[]' : k, v); });
		});
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
	}

	/* ------------------------------------------------------------ confirm dialog */
	function confirmBox(text, ok) {
		var last = document.activeElement;
		var wrap = document.createElement('div');
		wrap.className = 'ka-lbi-modal';
		wrap.innerHTML = '<div class="ka-lbi-modal-box" role="alertdialog" aria-modal="true" aria-labelledby="ka-lbi-mt" aria-describedby="ka-lbi-md"><h3 id="ka-lbi-mt"></h3><p id="ka-lbi-md"></p><div class="ka-lbi-modal-act"><button type="button" class="button" data-no></button><button type="button" class="button button-primary" data-yes></button></div></div>';
		$('h3', wrap).textContent = t.confirm || 'Please confirm';
		$('p', wrap).textContent = text;
		$('[data-no]', wrap).textContent = t.cancel || 'Cancel';
		$('[data-yes]', wrap).textContent = t.yes || 'OK';
		function close() { wrap.remove(); document.removeEventListener('keydown', key); if (last) { last.focus(); } }
		function key(e) { if (e.key === 'Escape') { close(); } }
		$('[data-no]', wrap).addEventListener('click', close);
		$('[data-yes]', wrap).addEventListener('click', function () { close(); ok(); });
		wrap.addEventListener('click', function (e) { if (e.target === wrap) { close(); } });
		document.addEventListener('keydown', key);
		document.body.appendChild(wrap);
		$('[data-yes]', wrap).focus();
	}

	document.addEventListener('click', function (e) {
		var a = e.target.closest('a.ka-lbi-confirm');
		if (a) {
			e.preventDefault();
			confirmBox(a.getAttribute('data-confirm'), function () { window.location.href = a.href; });
		}
	});
	// Submit buttons with data-confirm (form.requestSubmit keeps the clicked button's value).
	document.addEventListener('submit', function (e) {
		var btn = e.submitter;
		if (!btn || !btn.hasAttribute('data-confirm') || btn.dataset.confirmed) { return; }
		e.preventDefault();
		confirmBox(btn.getAttribute('data-confirm'), function () {
			btn.dataset.confirmed = '1';
			e.target.requestSubmit(btn);
		});
	}, true);

	$$('[data-ka-lbi-autosubmit]').forEach(function (s) {
		s.addEventListener('change', function () { s.form.submit(); });
	});
	$$('[data-ka-lbi-lang-switch]').forEach(function (s) {
		s.addEventListener('change', function () { window.location.href = s.getAttribute('data-ka-lbi-lang-switch') + '&lang=' + encodeURIComponent(s.value); });
	});

	/* ------------------------------------------------------------ chip pickers */
	function pickerCount(p) {
		var n = $$('input:checked', p).length;
		var out = $('.ka-lbi-picker-count', p);
		if (out) {
			out.textContent = n ? n + ' ' + (t.selected || '') : (p.hasAttribute('data-ka-lbi-cities') ? '' : (t.all_cats || ''));
		}
	}
	$$('[data-ka-lbi-picker]').forEach(function (p) {
		var filter = $('.ka-lbi-picker-filter', p);
		if (filter) {
			filter.addEventListener('input', function () {
				var q = filter.value.trim().toLowerCase();
				$$('.ka-lbi-chip', p).forEach(function (c) {
					c.hidden = q !== '' && (c.getAttribute('data-name') || '').indexOf(q) === -1 && c.textContent.toLowerCase().indexOf(q) === -1;
				});
			});
			filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
		}
		$$('[data-pick]', p).forEach(function (b) {
			b.addEventListener('click', function () {
				var on = b.getAttribute('data-pick') === 'all';
				$$('.ka-lbi-chip', p).forEach(function (c) { if (!c.hidden) { $('input', c).checked = on; } });
				pickerCount(p);
				p.dispatchEvent(new Event('change', { bubbles: true }));
			});
		});
		p.addEventListener('change', function () { pickerCount(p); });
		pickerCount(p);
	});

	/* ------------------------------------------------------------ discover */
	var disc = $('[data-ka-lbi-discover]');
	if (disc) {
		var cities = $('[data-ka-lbi-cities]', disc);
		var cats = $$('input[name="categories[]"]', disc);
		var bar = $('[data-ka-lbi-runbar]', disc);
		var timer = null;
		var update = function () {
			var cityIds = $$('input[name="locations[]"]:checked', disc).map(function (i) { return i.value; });
			var typed = ($('[name="new_places"]', disc).value || '').split(/[\r\n,;]+/).filter(function (s) { return s.trim(); }).length;
			var nCity = cityIds.length + typed;
			var nCat = cats.filter(function (c) { return c.checked; }).length || cats.length;
			var combos = nCity * nCat;
			var prov = $('input[name="provider"]:checked', disc);
			var max = parseInt($('[name="max"]', disc).value, 10) || 0;
			var hint = combos > 24 ? (t.background || '') : (t.preview || '');
			var cost = prov ? parseFloat(prov.getAttribute('data-cost')) || 0 : 0;
			if (cost > 0 && combos) {
				hint += ' · ' + (t.about || '') + ' $' + (cost * combos * max).toFixed(2);
			}
			$('[data-k="combos"]', bar).textContent = combos;
			$('[data-k="hint"]', bar).textContent = nCity ? hint : (t.no_city || '');
			clearTimeout(timer);
			timer = setTimeout(function () { coverage(cityIds); }, 350);
		};
		var lastKey = '';
		var coverage = function (ids) {
			var key = ids.join(',');
			if (key === lastKey) { return; }
			lastKey = key;
			post('ka_lbi_coverage', { lang: disc.getAttribute('data-lang'), locations: ids }).then(function (r) {
				if (!r || !r.success) { return; }
				$$('.ka-lbi-chip[data-slug]', disc).forEach(function (chip) {
					var slug = chip.getAttribute('data-slug');
					var n = r.data.counts[slug] || 0;
					var em = $('.ka-lbi-chip-n', chip);
					em.textContent = ids.length && n ? n : '';
					em.title = t.existing || '';
					chip.classList.toggle('is-recent', !!r.data.recent[slug]);
					chip.title = r.data.recent[slug] ? (t.recent || '') + ' (' + r.data.recent[slug] + ')' : '';
				});
			});
		};
		disc.addEventListener('change', update);
		disc.addEventListener('input', function (e) { if (e.target.name === 'max' || e.target.name === 'new_places') { update(); } });
		disc.addEventListener('submit', function (e) {
			var any = $$('input[name="locations[]"]:checked', disc).length || ($('[name="new_places"]', disc).value || '').trim();
			if (!any) {
				e.preventDefault();
				var d = $('.ka-lbi-more', disc);
				if (d) { d.open = true; }
				cities.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		});
		update();
	}

	/* ------------------------------------------------------------ preview */
	var prev = $('[data-ka-lbi-preview]');
	if (prev) {
		var rows = $$('.ka-lbi-row', prev);
		var chosen = $('[data-k="chosen"]', prev);
		var refresh = function () {
			var n = 0;
			rows.forEach(function (r) {
				var v = $('.ka-lbi-act', r).value;
				r.classList.toggle('is-skip', v === 'skip');
				if (v !== 'skip') { n++; }
			});
			chosen.textContent = n;
		};
		prev.addEventListener('change', function (e) { if (e.target.classList.contains('ka-lbi-act')) { refresh(); } });
		$$('[data-filter]', prev).forEach(function (b) {
			b.addEventListener('click', function () {
				$$('[data-filter]', prev).forEach(function (x) { x.classList.toggle('is-on', x === b); });
				var f = b.getAttribute('data-filter');
				rows.forEach(function (r) { r.hidden = f !== 'all' && r.getAttribute('data-kind') !== f; });
			});
		});
		$$('[data-set]', prev).forEach(function (b) {
			b.addEventListener('click', function () {
				var val = b.getAttribute('data-set');
				var scope = b.getAttribute('data-scope');
				rows.forEach(function (r) {
					if (scope === 'all' || r.getAttribute('data-kind') === scope) {
						var s = $('.ka-lbi-act', r);
						if ([].some.call(s.options, function (o) { return o.value === val; })) { s.value = val; }
					}
				});
				refresh();
			});
		});
		refresh();
	}

	/* ------------------------------------------------------------ bulk forms (review) */
	$$('[data-ka-lbi-bulkform]').forEach(function (form) {
		var boxes = $$('input[name="ids[]"]', form);
		var all = $('[data-ka-lbi-checkall]', form);
		var count = $('[data-ka-lbi-selcount]', form);
		var sync = function () {
			var n = boxes.filter(function (b) { return b.checked; }).length;
			if (count) { count.textContent = n ? n + ' ' + (t.selected || '') : ''; }
			$$('[data-needs-selection]', form).forEach(function (b) { b.disabled = !n; });
			if (all) { all.checked = n && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
		};
		if (all) { all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); sync(); }); }
		boxes.forEach(function (b) { b.addEventListener('change', sync); });
		$$('[data-ka-lbi-one]', form).forEach(function (b) {
			b.addEventListener('click', function () {
				var go = function () {
					$('[name="one"]', form).value = b.getAttribute('data-id');
					var d = $('input[type=hidden][name="do"]', form);
					d.disabled = false;
					d.value = b.getAttribute('data-ka-lbi-one');
					form.submit();
				};
				if (b.hasAttribute('data-confirm')) { confirmBox(b.getAttribute('data-confirm'), go); } else { go(); }
			});
		});
		sync();
	});

	/* ------------------------------------------------------------ reject with reasons (review queue) */
	$$('[data-ka-lbi-reject]').forEach(function (b) {
		b.addEventListener('click', function (e) {
			e.preventDefault();
			var tpl = document.getElementById('ka-lbi-reject-tpl');
			if (!tpl) { return; }
			var last = document.activeElement;
			var wrap = tpl.content.firstElementChild.cloneNode(true);
			var form = $('form', wrap);
			var issues = (b.getAttribute('data-issues') || '').split(',');
			var who = b.getAttribute('data-submitter') || '';
			$('[name="post"]', form).value = b.getAttribute('data-ka-lbi-reject');
			$('[data-title]', wrap).textContent = b.getAttribute('data-title') || '';
			$$('[name="reasons[]"]', form).forEach(function (c) { c.checked = issues.indexOf(c.value) !== -1; });
			var notify = $('[name="notify"]', form);
			notify.checked = !!who;
			notify.disabled = !who;
			$('[data-notify-text]', wrap).textContent = who ? (t.notify_to || '%s').replace('%s', who) : (t.no_mail || '');
			function close() { wrap.remove(); document.removeEventListener('keydown', key); if (last) { last.focus(); } }
			function key(e) { if (e.key === 'Escape') { close(); } }
			$('[data-no]', wrap).addEventListener('click', close);
			wrap.addEventListener('click', function (e) { if (e.target === wrap) { close(); } });
			document.addEventListener('keydown', key);
			document.body.appendChild(wrap);
			var first = $('[name="reasons[]"]', form);
			if (first) { first.focus(); }
		});
	});

	/* ------------------------------------------------------------ live job cards */
	$$('[data-ka-lbi-job]').forEach(function (card) {
		var type = card.getAttribute('data-ka-lbi-job');
		var labels = t.counts || {};
		var tick = function () {
			post('ka_lbi_poll_job', { type: type }).then(function (r) {
				if (!r || !r.success) { return; }
				var j = r.data.job;
				if (!j) {
					$('.ka-lbi-job-label', card).textContent = t.done || 'Done';
					$('.ka-lbi-bar span', card).style.width = '100%';
					setTimeout(function () { window.location.reload(); }, 1500);
					return;
				}
				$('[data-k="done"]', card).textContent = j.done;
				$('[data-k="total"]', card).textContent = j.total;
				$('[data-k="pct"]', card).textContent = j.pct;
				$('.ka-lbi-bar span', card).style.width = j.pct + '%';
				$('.ka-lbi-bar', card).setAttribute('aria-valuenow', j.pct);
				var parts = [];
				Object.keys(j.counts || {}).forEach(function (k) { if (labels[k]) { parts.push(j.counts[k] + ' ' + labels[k]); } });
				$('[data-k="counts"]', card).textContent = parts.length ? '· ' + parts.join(' · ') : '';
				var log = $('[data-k="messages"]', card);
				log.innerHTML = '';
				(j.messages || []).forEach(function (m) { var li = document.createElement('li'); li.textContent = m; log.appendChild(li); });
				$('[data-k="error"]', card).textContent = j.last_error || '';
				setTimeout(tick, 6000);
			}).catch(function () { setTimeout(tick, 15000); });
		};
		setTimeout(tick, 1500);
	});
})();
