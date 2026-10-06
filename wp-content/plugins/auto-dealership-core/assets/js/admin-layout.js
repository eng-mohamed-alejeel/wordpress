/* Keep operational tables intact while containing overflow on small screens. */
document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('.adc-admin .wrap table.widefat').forEach(table => {
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
		if (caption) region.setAttribute('aria-label', caption.textContent.trim().replace(/\s+/g, ' '));
	});
});
