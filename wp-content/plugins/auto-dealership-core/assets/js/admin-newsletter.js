/* Live previews never save a draft or send email. */
(function ($) {
    'use strict';
    document.querySelectorAll('.adc-nl-management form').forEach(form => {
        form.addEventListener('submit', event => {
            const button = event.submitter;
            if (!button) return;
            if (button.dataset.newsletterConfirm && !window.confirm(button.dataset.newsletterConfirm)) {
                event.preventDefault(); return;
            }
            const operation = document.createElement('input');
            operation.type = 'hidden'; operation.name = 'operation'; operation.value = button.value;
            form.appendChild(operation); button.disabled = true;
        });
    });
    const frame = document.querySelector('[data-preview-frame]');
    if (!frame) return;
    const buttons = Array.from(document.querySelectorAll('[data-preview-language]'));
    const status = document.querySelector('[data-preview-status]');
    const form = document.querySelector('.adc-nl-language-block')?.closest('form');
    const send = form?.querySelector('[data-newsletter-send]');
    const config = window.adcNewsletterPreview;
    let language = buttons.find(button => button.getAttribute('aria-pressed') === 'true')?.dataset.previewLanguage || 'ar';
    let timer, request, revision = 0;
    function synchronizeEditors() {
        if (!form || !window.tinymce) return;
        ['ar', 'en'].forEach(lang => {
            const editor = window.tinymce.get('adc_nl_body_' + lang);
            if (editor && !editor.isHidden()) editor.save();
        });
    }
    function complete() {
        if (!send) return;
        synchronizeEditors();
        const data = new FormData(form);
        const ready = ['ar', 'en'].every(lang => {
            const subject = String(data.get('subject_' + lang) || '').trim();
            const body = document.createElement('div'); body.innerHTML = String(data.get('body_' + lang) || '');
            const button = String(data.get('button_text_' + lang) || '').trim();
            const url = String(data.get('button_url_' + lang) || '').trim();
            return subject && body.textContent.trim() && (!button || (/^https?:\/\//i.test(url) && form.elements['button_url_' + lang].checkValidity()));
        });
        send.disabled = !ready || Number(send.dataset.activeSubscribers) === 0;
    }
    async function update() {
        if (!form || !config) return;
        complete();
        const version = ++revision;
        if (request) request.abort();
        request = new AbortController();
        const data = new FormData(form);
        data.set('action', 'adc_newsletter_preview'); data.set('nonce', config.nonce); data.set('preview_language', language);
        data.set('lang', config.interfaceLanguage);
        status.textContent = config.updating;
        try {
            const response = await fetch(config.url, {method: 'POST', body: data, credentials: 'same-origin', signal: request.signal});
            const payload = await response.json();
            if (version !== revision) return;
            if (!response.ok || !payload.success) throw new Error(payload.data?.message || config.failed);
            frame.srcdoc = payload.data.html;
            status.textContent = config.ready;
        } catch (error) {
            if (error.name !== 'AbortError' && version === revision) status.textContent = config.failed;
        }
    }
    function schedule() { complete(); clearTimeout(timer); timer = setTimeout(update, 220); }
    buttons.forEach(button => button.addEventListener('click', () => {
        language = button.dataset.previewLanguage;
        buttons.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        if (form) { clearTimeout(timer); update(); }
        else {
            const saved = document.querySelector('[data-saved-preview="' + language + '"]');
            if (saved) frame.srcdoc = saved.content.textContent;
        }
    }));
    if (!form) return;
    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    const attached = new WeakSet();
    function attach(editor) {
        if (!editor || !/^adc_nl_body_(ar|en)$/.test(editor.id) || attached.has(editor)) return;
        attached.add(editor);
        editor.on('input change keyup SetContent Undo Redo', schedule);
    }
    $(document).on('tinymce-editor-init', function (event, editor) { attach(editor); schedule(); });
    if (window.tinymce) window.tinymce.editors.forEach(attach);
    form.addEventListener('submit', event => {
        synchronizeEditors();
        const submitter = event.submitter;
        if (submitter?.value !== 'send') return;
        complete();
        if (send.disabled) { event.preventDefault(); return; }
        // Preserve the action before disabling the button to prevent double clicks.
        const action = document.createElement('input'); action.type = 'hidden'; action.name = 'operation'; action.value = 'send';
        form.appendChild(action); send.disabled = true;
    });
    complete();
    schedule();
})(jQuery);
