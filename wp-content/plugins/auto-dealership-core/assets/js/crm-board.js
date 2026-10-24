(function () {
	'use strict';

	var cfg = window.adcCrmBoard || {};
	var statusNode;
	var boardNode;
	var activeCard = null;
	var touch = null;

	function board() {
		boardNode = boardNode || document.querySelector('.adc-crm-board');
		return boardNode;
	}

	function announce(message, type) {
		statusNode = statusNode || document.querySelector('[data-crm-board-status]');
		if (statusNode) {
			statusNode.textContent = message;
			statusNode.classList.toggle('notice-error', type === 'error');
			statusNode.classList.toggle('is-success', type === 'success');
		}
	}

	function allowedStages(card) {
		try {
			var stages = JSON.parse(card.dataset.allowed || '[]');
			return Array.isArray(stages) ? stages : [];
		} catch (error) {
			return null;
		}
	}

	function stageName(stage) {
		var name = document.querySelector('[data-lead-stage="' + stage + '"] .adc-crm-stage-name');
		return name ? name.textContent : stage;
	}

	function updateCounts() {
		document.querySelectorAll('[data-lead-stage]').forEach(function (column) {
			var count = column.querySelectorAll('[data-lead-card]').length;
			var node = column.querySelector('[data-stage-count]');
			if (node) {
				node.textContent = String(count);
			}
		});
	}

	function markTargets(card) {
		var allowed = allowedStages(card) || [];
		var root = board();
		if (root) {
			root.classList.add('is-dragging-active');
		}
		document.querySelectorAll('[data-lead-stage]').forEach(function (column) {
			column.classList.toggle('is-valid-target', allowed.indexOf(column.dataset.leadStage) >= 0);
		});
	}

	function clearTargets() {
		var root = board();
		if (root) {
			root.classList.remove('is-dragging-active');
		}
		document.querySelectorAll('[data-lead-stage]').forEach(function (column) {
			column.classList.remove('is-valid-target', 'is-over');
		});
	}

	function askReason(message) {
		return new Promise(function (resolve) {
			if (typeof HTMLDialogElement === 'undefined') {
				resolve(window.prompt(message) || '');
				return;
			}
			var dialog = document.createElement('dialog');
			dialog.className = 'adc-crm-board-dialog';
			var form = document.createElement('form');
			form.method = 'dialog';
			var label = document.createElement('label');
			label.textContent = message;
			label.style.fontWeight = '600';
			var area = document.createElement('textarea');
			area.required = true;
			area.maxLength = 500;
			var menu = document.createElement('menu');
			var cancel = document.createElement('button');
			cancel.type = 'button';
			cancel.className = 'button';
			cancel.textContent = cfg.cancelLabel || 'إلغاء';
			var ok = document.createElement('button');
			ok.type = 'submit';
			ok.className = 'button button-primary';
			ok.textContent = cfg.confirmLabel || 'تأكيد';
			menu.appendChild(cancel);
			menu.appendChild(ok);
			label.appendChild(area);
			form.appendChild(label);
			form.appendChild(menu);
			dialog.appendChild(form);
			document.body.appendChild(dialog);
			var value = '';
			cancel.addEventListener('click', function () { dialog.close(); });
			form.addEventListener('submit', function () { value = area.value.trim(); });
			dialog.addEventListener('close', function () {
				document.body.removeChild(dialog);
				resolve(value);
			});
			dialog.showModal();
			area.focus();
		});
	}

	function requestStage(card, targetStage) {
		if (card.dataset.pending === '1') {
			return;
		}
		var allowed = allowedStages(card);
		if (!allowed || allowed.indexOf(targetStage) < 0) {
			if (card.dataset.needsContact === '1' && targetStage === 'contacted') {
				announce('سجّل أول تواصل مع العميل من تفاصيل الفرصة قبل نقلها إلى «تم التواصل».', 'error');
			} else {
				announce('هذا الانتقال غير مسموح من المرحلة الحالية.', 'error');
			}
			return;
		}
		var isAssign = card.dataset.stage === 'new' && targetStage === 'assigned';
		var needsReason = targetStage === 'lost' || (card.dataset.stage === 'lost' && targetStage === 'new');
		var inputPromise;
		if (isAssign && card.dataset.assignStaff) {
			inputPromise = askStaff(JSON.parse(card.dataset.assignStaff));
		} else if (needsReason) {
			inputPromise = askReason(targetStage === 'lost' ? 'أدخل سبب فقدان الفرصة:' : 'أدخل سبب إعادة فتح الفرصة:');
		} else {
			inputPromise = Promise.resolve('');
		}
		inputPromise.then(function (reason) {
			if ((needsReason || (isAssign && card.dataset.assignStaff)) && !reason) {
				announce(isAssign ? 'اختر الموظف لإسناد الفرصة.' : (targetStage === 'lost' ? 'سبب الفقد مطلوب.' : 'سبب إعادة الفتح مطلوب.'), 'error');
				return;
			}
			sendMove(card, targetStage, reason);
		});
	}

	function askStaff(staff) {
		return new Promise(function (resolve) {
			var dialog = document.createElement('dialog');
			dialog.className = 'adc-crm-board-dialog';
			var form = document.createElement('form');
			form.method = 'dialog';
			var label = document.createElement('label');
			label.textContent = 'اختر موظف المبيعات لإسناد الفرصة:';
			label.style.fontWeight = '600';
			var select = document.createElement('select');
			select.required = true;
			select.style.width = '100%';
			staff.forEach(function (member) {
				var option = document.createElement('option');
				option.value = String(member.id);
				option.textContent = member.name;
				select.appendChild(option);
			});
			var menu = document.createElement('menu');
			var cancel = document.createElement('button');
			cancel.type = 'button';
			cancel.className = 'button';
			cancel.textContent = 'إلغاء';
			var ok = document.createElement('button');
			ok.type = 'submit';
			ok.className = 'button button-primary';
			ok.textContent = 'إسناد';
			menu.appendChild(cancel);
			menu.appendChild(ok);
			label.appendChild(select);
			form.appendChild(label);
			form.appendChild(menu);
			dialog.appendChild(form);
			document.body.appendChild(dialog);
			var value = '';
			cancel.addEventListener('click', function () { dialog.close(); });
			form.addEventListener('submit', function () { value = select.value; });
			dialog.addEventListener('close', function () {
				document.body.removeChild(dialog);
				resolve(value);
			});
			dialog.showModal();
			select.focus();
		});
	}

	function sendMove(card, targetStage, reason) {
		var previousColumn = card.parentNode;
		var wasNew = card.dataset.stage === 'new';
		var destination = document.querySelector('[data-lead-stage="' + targetStage + '"]');
		if (!destination) {
			announce('تعذر العثور على مرحلة الوجهة.', 'error');
			return;
		}
		card.dataset.pending = '1';
		card.setAttribute('aria-busy', 'true');
		announce('جارٍ تحديث المرحلة...');
		destination.appendChild(card);
		updateCounts();

		var payload;
		if (wasNew && targetStage === 'assigned') {
			payload = card.dataset.assignStaff
				? { action: 'adc_assign_lead_ajax', nonce: cfg.nonce, lead_id: card.dataset.leadId, staff_id: reason }
				: { action: 'adc_claim_lead_ajax', nonce: cfg.nonce, lead_id: card.dataset.leadId };
		} else {
			payload = { action: 'adc_move_lead_stage', nonce: cfg.nonce, lead_id: card.dataset.leadId, stage: targetStage, reason: reason };
		}

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams(payload)
		}).then(function (response) {
			return response.json().then(function (result) {
				if (!response.ok || !result.success) {
					throw new Error(result.data && result.data.message ? result.data.message : 'تعذر تحديث المرحلة.');
				}
				return result.data;
			});
		}).then(function (result) {
			var next = Array.isArray(result.allowed_stages) ? result.allowed_stages : [];
			card.dataset.stage = result.stage;
			card.dataset.allowed = JSON.stringify(next);
			card.classList.remove('is-fresh');
			card.draggable = result.stage !== 'new' && next.length > 0;
			announce('تم نقل الفرصة إلى «' + stageName(result.stage) + '».', 'success');
			if (wasNew) {
				window.setTimeout(function () { window.location.reload(); }, 600);
			}
		}).catch(function (error) {
			previousColumn.appendChild(card);
			announce(error.message || 'تعذر الاتصال بالخادم.', 'error');
		}).finally(function () {
			delete card.dataset.pending;
			card.removeAttribute('aria-busy');
			updateCounts();
		});
	}

	document.addEventListener('dragstart', function (event) {
		var card = event.target.closest && event.target.closest('[data-lead-card]');
		if (!card || card.dataset.pending === '1') {
			return;
		}
		activeCard = card;
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData('text/plain', card.dataset.leadId);
		card.classList.add('is-dragging');
		markTargets(card);
	});

	document.addEventListener('dragend', function () {
		if (activeCard) {
			activeCard.classList.remove('is-dragging');
		}
		activeCard = null;
		clearTargets();
	});

	document.addEventListener('dragover', function (event) {
		var column = event.target.closest && event.target.closest('[data-lead-stage]');
		if (!column || !activeCard) {
			return;
		}
		if (column.classList.contains('is-valid-target')) {
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
		}
		document.querySelectorAll('[data-lead-stage].is-over').forEach(function (node) {
			if (node !== column) {
				node.classList.remove('is-over');
			}
		});
		column.classList.add('is-over');
	});

	document.addEventListener('drop', function (event) {
		var column = event.target.closest && event.target.closest('[data-lead-stage]');
		if (!column || !activeCard) {
			return;
		}
		event.preventDefault();
		var card = activeCard;
		var stage = column.dataset.leadStage;
		clearTargets();
		if (card.dataset.stage !== stage) {
			requestStage(card, stage);
		}
	});

	// Touch devices do not fire HTML5 drag events, so emulate them with pointer events.
	document.addEventListener('pointerdown', function (event) {
		if (event.pointerType !== 'touch') {
			return;
		}
		var card = event.target.closest && event.target.closest('[data-lead-card][draggable="true"]');
		if (!card || event.target.closest('a,button,select,input,textarea') || card.dataset.pending === '1') {
			return;
		}
		touch = { card: card, id: event.pointerId, x: event.clientX, y: event.clientY, startX: event.clientX, startY: event.clientY, ghost: null };
	});

	function startTouchDrag() {
		var ghost = touch.card.cloneNode(true);
		ghost.classList.add('is-touch-ghost');
		ghost.removeAttribute('data-lead-card');
		document.body.appendChild(ghost);
		touch.ghost = ghost;
		activeCard = touch.card;
		touch.card.classList.add('is-dragging');
		markTargets(touch.card);
	}

	function moveGhost(x, y) {
		if (touch && touch.ghost) {
			touch.ghost.style.left = (x - 120) + 'px';
			touch.ghost.style.top = (y - 24) + 'px';
		}
	}

	function columnAt(x, y) {
		var node = document.elementFromPoint(x, y);
		return node && node.closest ? node.closest('[data-lead-stage]') : null;
	}

	document.addEventListener('pointermove', function (event) {
		if (!touch || event.pointerId !== touch.id) {
			return;
		}
		touch.x = event.clientX;
		touch.y = event.clientY;
		if (!touch.ghost) {
			if (Math.abs(touch.x - touch.startX) + Math.abs(touch.y - touch.startY) > 6) {
				startTouchDrag();
			} else {
				return;
			}
		}
		event.preventDefault();
		moveGhost(touch.x, touch.y);
		var column = columnAt(touch.x, touch.y);
		document.querySelectorAll('[data-lead-stage].is-over').forEach(function (node) {
			if (node !== column) {
				node.classList.remove('is-over');
			}
		});
		if (column) {
			column.classList.add('is-over');
		}
	}, { passive: false });

	function endTouch(event, cancelled) {
		if (!touch || event.pointerId !== touch.id) {
			return;
		}
		var state = touch;
		touch = null;
		if (!state.ghost) {
			return;
		}
		document.body.removeChild(state.ghost);
		state.card.classList.remove('is-dragging');
		var column = cancelled ? null : columnAt(event.clientX, event.clientY);
		activeCard = null;
		clearTargets();
		if (column && column.dataset.leadStage !== state.card.dataset.stage) {
			requestStage(state.card, column.dataset.leadStage);
		}
	}

	document.addEventListener('pointerup', function (event) { endTouch(event, false); });
	document.addEventListener('pointercancel', function (event) { endTouch(event, true); });

	function openDetails(url, title) {
		var dialog = document.createElement('dialog');
		dialog.className = 'adc-crm-details-dialog';
		var bar = document.createElement('div');
		bar.className = 'adc-crm-details-bar';
		var heading = document.createElement('strong');
		heading.textContent = title;
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'button';
		close.textContent = '✕ إغلاق';
		close.setAttribute('aria-label', 'إغلاق نافذة التفاصيل');
		bar.appendChild(heading);
		bar.appendChild(close);
		var frame = document.createElement('iframe');
		frame.className = 'adc-crm-details-frame';
		frame.title = title;
		frame.src = url + (url.indexOf('?') < 0 ? '?' : '&') + 'adc_modal=1';
		frame.addEventListener('load', function () {
			loads += 1;
			if (loads > 1) {
				dialog.close();
			}
		});
		dialog.appendChild(bar);
		dialog.appendChild(frame);
		document.body.appendChild(dialog);
		var loads = 0;
		close.addEventListener('click', function () { dialog.close(); });
		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) {
				dialog.close();
			}
		});
		dialog.addEventListener('close', function () {
			document.body.removeChild(dialog);
			// A second load means a form inside the dialog was submitted.
			if (loads > 1) {
				window.location.reload();
			}
		});
		dialog.showModal();
	}

	var dragMoved = false;
	function suppressClick() {
		dragMoved = true;
		window.setTimeout(function () { dragMoved = false; }, 400);
	}
	document.addEventListener('dragend', suppressClick, true);
	document.addEventListener('pointerup', function () { if (touch && touch.ghost) { suppressClick(); } }, true);

	document.addEventListener('click', function (event) {
		var card = event.target.closest && event.target.closest('[data-lead-card]');
		if (!card || dragMoved || event.target.closest('button,select,input,textarea,form')) {
			return;
		}
		var link = card.querySelector('[data-lead-details]');
		var anchor = event.target.closest('a');
		if (!link || (anchor && anchor !== link)) {
			return;
		}
		event.preventDefault();
		var name = card.querySelector('.adc-crm-card-head strong');
		openDetails(link.href, name ? name.textContent : 'تفاصيل الفرصة');
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Enter' && event.target.matches && event.target.matches('[data-lead-card]')) {
			var link = event.target.querySelector('[data-lead-details]');
			if (link) {
				openDetails(link.href, link.textContent);
			}
		}
	});

	if (window.self !== window.top) {
		document.documentElement.classList.add('adc-in-modal');
	}

	updateCounts();
})();
