(function () {
	'use strict';

	var cfg = window.adcCrmBoard || {};
	var statusNode;

	function announce(message, isError) {
		if (!statusNode) {
			statusNode = document.querySelector('[data-crm-board-status]');
		}
		if (statusNode) {
			statusNode.textContent = message;
			statusNode.classList.toggle('notice-error', Boolean(isError));
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

	function refreshStageSelect(card, stages) {
		var select = card.querySelector('[data-lead-stage-form] select[name="stage"]');
		if (!select) {
			return;
		}
		while (select.firstChild) {
			select.removeChild(select.firstChild);
		}
		var placeholder = document.createElement('option');
		placeholder.value = '';
		placeholder.textContent = 'اختر المرحلة التالية';
		select.appendChild(placeholder);
		stages.forEach(function (stage) {
			var column = document.querySelector('[data-lead-stage="' + stage + '"] h2');
			if (!column) {
				return;
			}
			var option = document.createElement('option');
			option.value = stage;
			option.textContent = column.textContent;
			select.appendChild(option);
		});
	}

	function requestStage(card, targetStage) {
		if (card.dataset.pending === '1') {
			return;
		}
		var allowed = allowedStages(card);
		if (!allowed || allowed.indexOf(targetStage) < 0) {
			announce('هذا الانتقال غير مسموح من المرحلة الحالية.', true);
			return;
		}
		var reason = '';
		if (targetStage === 'lost' || (card.dataset.stage === 'lost' && targetStage === 'new')) {
			reason = window.prompt(targetStage === 'lost' ? 'أدخل سبب فقدان الفرصة:' : 'أدخل سبب إعادة فتح الفرصة:') || '';
			if (!reason.trim()) {
				announce(targetStage === 'lost' ? 'سبب الفقد مطلوب.' : 'سبب إعادة الفتح مطلوب.', true);
				return;
			}
		}

		var previousColumn = card.parentNode;
		var destination = document.querySelector('[data-lead-stage="' + targetStage + '"]');
		if (!destination) {
			announce('تعذر العثور على مرحلة الوجهة.', true);
			return;
		}
		card.dataset.pending = '1';
		card.setAttribute('aria-busy', 'true');
		announce('جارٍ تحديث المرحلة...', false);
		var body = new URLSearchParams({
			action: 'adc_move_lead_stage',
			nonce: cfg.nonce,
			lead_id: card.dataset.leadId,
			stage: targetStage,
			reason: reason
		});

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		}).then(function (response) {
			return response.json().then(function (result) {
				if (!response.ok || !result.success) {
					throw new Error(result.data && result.data.message ? result.data.message : 'تعذر تحديث المرحلة.');
				}
				return result.data;
			});
		}).then(function (result) {
			destination.appendChild(card);
			card.dataset.stage = result.stage;
			card.dataset.allowed = JSON.stringify(Array.isArray(result.allowed_stages) ? result.allowed_stages : []);
			card.draggable = result.stage !== 'new';
			refreshStageSelect(card, Array.isArray(result.allowed_stages) ? result.allowed_stages : []);
			announce('تم تحديث مرحلة الفرصة بنجاح.', false);
		}).catch(function (error) {
			if (card.parentNode !== previousColumn) {
				previousColumn.appendChild(card);
			}
			announce(error.message || 'تعذر الاتصال بالخادم.', true);
		}).finally(function () {
			delete card.dataset.pending;
			card.removeAttribute('aria-busy');
		});
	}

	document.addEventListener('dragstart', function (event) {
		var card = event.target.closest('[data-lead-card]');
		if (card && card.dataset.pending !== '1') {
			event.dataTransfer.setData('text/plain', card.dataset.leadId);
			card.classList.add('is-dragging');
		}
	});

	document.addEventListener('dragend', function (event) {
		var card = event.target.closest('[data-lead-card]');
		if (card) {
			card.classList.remove('is-dragging');
		}
	});

	document.addEventListener('dragover', function (event) {
		var column = event.target.closest('[data-lead-stage]');
		if (column) {
			event.preventDefault();
		}
	});

	document.addEventListener('drop', function (event) {
		var column = event.target.closest('[data-lead-stage]');
		if (!column) {
			return;
		}
		event.preventDefault();
		var cardId = event.dataTransfer.getData('text/plain');
		var card = document.querySelector('[data-lead-card][data-lead-id="' + cardId + '"]');
		if (card && card.dataset.stage !== column.dataset.leadStage) {
			requestStage(card, column.dataset.leadStage);
		}
	});

	document.addEventListener('submit', function (event) {
		var form = event.target.closest('[data-lead-stage-form]');
		if (!form) {
			return;
		}
		event.preventDefault();
		var card = form.closest('[data-lead-card]');
		var select = form.querySelector('select[name="stage"]');
		if (card && select && select.value) {
			requestStage(card, select.value);
		}
	});
})();
