/* Keep operational tables intact while containing overflow on small screens. */
document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('.adc-admin .wrap table.widefat').forEach((table, index) => {
		let region = table.parentElement;
		if (!region.classList.contains('adc-table-scroll')) {
			region = document.createElement('div');
			region.className = 'adc-table-scroll';
			table.before(region);
			region.append(table);
		}
		region.tabIndex = 0;
		region.setAttribute('role', 'region');
		const caption = table.caption || table.querySelector('thead');
		if (caption) region.setAttribute('aria-label', caption.textContent.trim().replace(/\s+/g, ' ') + ' (' + (index + 1) + ')');
	});

	// WordPress notices are server-rendered without a consistent live-region contract.
	// Keep the announcement scoped to plugin screens and do not replace an explicit role.
	const notices = [...document.querySelectorAll('.adc-admin .notice')];
	notices.forEach(notice => {
		if (!notice.hasAttribute('role')) notice.setAttribute('role', notice.classList.contains('notice-error') ? 'alert' : 'status');
		if (!notice.hasAttribute('aria-live')) notice.setAttribute('aria-live', notice.classList.contains('notice-error') ? 'assertive' : 'polite');
	});

	// A redirected POST has no browser focus to preserve. Move it to the result only
	// when the URL explicitly carries a save/error result, leaving ordinary page loads alone.
	const resultKeys = ['saved', 'updated', 'created', 'error', 'result', 'notice', 'workspace_notice', 'branch_created', 'branch_error', 'merged', 'migrated', 'drafts_created', 'onyx_saved', 'onyx_error'];
	const query = new URLSearchParams(window.location.search);
	const hasResult = resultKeys.some(key => query.has(key));
	if (hasResult && !window.location.hash) {
		const resultNotice = notices.find(notice => notice.matches('.notice-success, .notice-error')) || notices.find(notice => notice.matches('.notice-warning'));
		if (resultNotice) {
			resultNotice.tabIndex = -1;
			window.setTimeout(() => resultNotice.focus({ preventScroll: true }), 100);
		}
	}
	if (!window.location.hash && !hasResult) {
		const implicitResult = notices.find(notice => notice.matches('.notice-success, .notice-error'));
		if (implicitResult) {
			implicitResult.tabIndex = -1;
			window.setTimeout(() => implicitResult.focus({ preventScroll: true }), 100);
		}
	}

	// Native constraint validation is useful, but its message is not consistently
	// exposed to assistive technology. Associate a generated, localized browser
	// message with the invalid control without changing the form submission flow.
	let validationId = 0;
	const markInvalid = control => {
		if (!control.validationMessage) return;
		let message = control.nextElementSibling;
		if (!message || !message.matches('.adc-validation-message[data-generated="1"]')) {
			message = document.createElement('span');
			message.className = 'screen-reader-text adc-validation-message';
			message.dataset.generated = '1';
			message.id = `adc-validation-message-${++validationId}`;
			control.insertAdjacentElement('afterend', message);
		}
		message.textContent = control.validationMessage;
		const describedBy = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
		if (!describedBy.includes(message.id)) describedBy.push(message.id);
		control.setAttribute('aria-describedby', describedBy.join(' '));
		control.setAttribute('aria-invalid', 'true');
	};
	const clearInvalid = control => {
		const message = control.nextElementSibling;
		if (!message || !message.matches('.adc-validation-message[data-generated="1"]')) return;
		const describedBy = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && id !== message.id);
		if (describedBy.length) control.setAttribute('aria-describedby', describedBy.join(' '));
		else control.removeAttribute('aria-describedby');
		control.removeAttribute('aria-invalid');
		message.remove();
	};
	document.addEventListener('invalid', event => {
		const control = event.target;
		if (control.matches?.('.adc-admin .wrap input, .adc-admin .wrap select, .adc-admin .wrap textarea') && control.type !== 'hidden') markInvalid(control);
	}, true);
	document.addEventListener('input', event => {
		const control = event.target;
		if (control.matches?.('.adc-admin .wrap input, .adc-admin .wrap select, .adc-admin .wrap textarea') && control.validity.valid) clearInvalid(control);
	});
	document.addEventListener('change', event => {
		const control = event.target;
		if (control.matches?.('.adc-admin .wrap input, .adc-admin .wrap select, .adc-admin .wrap textarea') && control.validity.valid) clearInvalid(control);
	});
});
