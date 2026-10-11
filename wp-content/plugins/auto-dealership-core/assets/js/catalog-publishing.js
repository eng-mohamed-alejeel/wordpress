'use strict';
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-media-field]').forEach(function (section) {
        const input = section.querySelector('input[type="hidden"]');
        const preview = section.querySelector('.adc-media-preview');
        section.querySelector('[data-select-images]').addEventListener('click', function () {
            const multiple = section.dataset.mediaMultiple === '1';
            const frame = wp.media({title: section.querySelector('h3').textContent, library: {type: 'image'}, multiple: multiple});
            frame.on('open', function () {
                const selection = frame.state().get('selection');
                input.value.split(',').filter(Number).forEach(function (id) { selection.add(wp.media.attachment(Number(id))); });
            });
            frame.on('select', function () {
                const images = frame.state().get('selection').toJSON().slice(0, multiple ? 20 : 1);
                input.value = images.map(function (item) { return item.id; }).join(',');
                preview.replaceChildren();
                images.forEach(function (item) {
                    const figure = document.createElement('figure'); figure.dataset.imageId = item.id;
                    const image = document.createElement('img'); image.src = item.sizes && item.sizes.thumbnail ? item.sizes.thumbnail.url : item.url; image.alt = item.alt || item.title || '';
                    const button = document.createElement('button'); button.type = 'button'; button.dataset.removeImage = ''; button.textContent = '×';
                    button.setAttribute('aria-label', document.documentElement.lang.startsWith('ar') ? 'إزالة الصورة' : 'Remove image');
                    figure.append(image, button); preview.append(figure);
                });
            });
            frame.open();
        });
        preview.addEventListener('click', function (event) {
            const button = event.target.closest('[data-remove-image]');
            if (!button) return;
            button.closest('figure').remove();
            const remaining = Array.from(preview.querySelectorAll('[data-image-id]')).map(function (figure) { return figure.dataset.imageId; });
            input.value = remaining.length ? remaining.join(',') : (section.dataset.mediaMultiple === '1' ? '' : '0');
        });
    });
    const model = document.querySelector('[data-model-select]');
    if (model) model.addEventListener('change', function () {
        const title = document.querySelector('input[name="title"]');
        if (model.value) title.value = model.options[model.selectedIndex].text;
    });
    const form = document.querySelector('.adc-publishing-form');
    if (form) {
        let changed = false;
		let submitting = false;
        form.addEventListener('change', function () { changed = true; });
        form.addEventListener('input', function () { changed = true; });
        form.addEventListener('submit', function () { changed = false; submitting = true; });
        window.addEventListener('beforeunload', function (event) {
            const descriptionChanged = window.tinymce && tinymce.editors.some(function (editor) { return editor.isDirty(); });
            if (!submitting && (changed || descriptionChanged)) { event.preventDefault(); event.returnValue = ''; }
        });
    }
});
