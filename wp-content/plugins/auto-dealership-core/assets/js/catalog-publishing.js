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
                const images = frame.state().get('selection').toJSON().slice(0, multiple ? (Number(section.dataset.mediaLimit)||20) : 1);
                input.value = images.map(function (item) { return item.id; }).join(',');
                input.dispatchEvent(new Event('change',{bubbles:true}));
                preview.replaceChildren();
                images.forEach(function (item) {
                    const figure = document.createElement('figure'); figure.dataset.imageId = item.id;figure.dataset.mainSrc=item.sizes&&item.sizes.medium?item.sizes.medium.url:item.url;
                    const image = document.createElement('img'); const size=item.sizes&&item.sizes[multiple?'thumbnail':'medium'];image.src=size?size.url:item.url; image.alt = item.alt || item.title || '';
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
            input.dispatchEvent(new Event('change',{bubbles:true}));
        });
    });
    document.querySelectorAll('[data-media-multiple="1"]').forEach(section => {
        const preview=section.querySelector('.adc-media-preview'),input=section.querySelector('input[type="hidden"]');
        let dragging=null;
        const update=()=>{ input.value=[...preview.querySelectorAll('[data-image-id]')].map(n=>n.dataset.imageId).join(',');input.dispatchEvent(new Event('change',{bubbles:true})); };
        const decorate=()=>{preview.querySelectorAll('figure').forEach(figure=>{
            if(figure.querySelector('.adc-media-order'))return;figure.draggable=true;
            const bar=document.createElement('div');bar.className='adc-media-order';
            [-1,1].forEach(delta=>{const button=document.createElement('button');button.type='button';button.textContent=delta<0?'←':'→';button.setAttribute('aria-label',document.documentElement.lang.startsWith('ar')?(delta<0?'تقديم الصورة':'تأخير الصورة'):(delta<0?'Move image earlier':'Move image later'));button.addEventListener('click',()=>{const sibling=delta<0?figure.previousElementSibling:figure.nextElementSibling;if(!sibling)return;if(delta<0)preview.insertBefore(figure,sibling);else preview.insertBefore(sibling,figure);update();});bar.append(button);});figure.append(bar);
        });};
        new MutationObserver(decorate).observe(preview,{childList:true});decorate();
        preview.addEventListener('dragstart',e=>{dragging=e.target.closest('figure');if(dragging)e.dataTransfer.setData('text/plain',dragging.dataset.imageId);});
        preview.addEventListener('dragover',e=>{if(dragging)e.preventDefault();});
        preview.addEventListener('drop',e=>{if(!dragging)return;e.preventDefault();const target=e.target.closest('figure');if(target&&target!==dragging){preview.insertBefore(dragging,target);update();}dragging=null;});
        preview.addEventListener('dragend',()=>{dragging=null;});
    });
    document.querySelectorAll('[data-color-media-card]').forEach(card=>{
        const ar=document.documentElement.lang.startsWith('ar'),main=card.querySelector('[data-color-main]'),extras=card.querySelector('[data-color-extras]'),mainPreview=main.querySelector('.adc-media-preview'),extraPreview=extras.querySelector('.adc-media-preview'),mainInput=main.querySelector('input[type="hidden"]'),extraInput=extras.querySelector('input[type="hidden"]');
        function update(){
            extraPreview.querySelectorAll('figure').forEach(figure=>{
                if(figure.dataset.imageId===mainInput.value){figure.remove();return;}
                if(figure.querySelector('[data-set-color-main]'))return;
                const button=document.createElement('button');button.type='button';button.className='adc-set-color-main';button.dataset.setColorMain='';button.textContent=ar?'تعيين كرئيسية':'Set as main';
                button.addEventListener('click',()=>{
                    const previous=mainPreview.querySelector('figure'),replacement=figure.cloneNode(true);replacement.querySelectorAll('.adc-media-order,[data-set-color-main]').forEach(n=>n.remove());replacement.draggable=false;const primaryPhoto=replacement.querySelector('img');if(primaryPhoto&&replacement.dataset.mainSrc){primaryPhoto.removeAttribute('srcset');primaryPhoto.removeAttribute('sizes');primaryPhoto.src=replacement.dataset.mainSrc;}
                    const previousId=mainInput.value;mainInput.value=figure.dataset.imageId;mainPreview.replaceChildren(replacement);figure.remove();
                    if(previous&&previousId!=='0'&&![...extraPreview.querySelectorAll('figure')].some(n=>n.dataset.imageId===previousId)){extraPreview.prepend(previous);}
                    mainInput.dispatchEvent(new Event('change',{bubbles:true}));update();
                });figure.append(button);
            });
            const ids=[...extraPreview.querySelectorAll('figure')].map(n=>n.dataset.imageId),value=ids.join(',');if(extraInput.value!==value){extraInput.value=value;extraInput.dispatchEvent(new Event('change',{bubbles:true}));}
            const ready=mainInput.value!==''&&mainInput.value!=='0';card.querySelector('[data-color-readiness]').textContent=ready?(ar?'الصورة الرئيسية محددة':'Main image ready'):(ar?'أضف صورة رئيسية':'Add a main image');card.querySelector('[data-color-readiness]').classList.toggle('is-missing',!ready);card.querySelector('[data-color-count]').textContent=(ar?'صور إضافية: ':'Additional photos: ')+ids.length;
        }
        new MutationObserver(update).observe(mainPreview,{childList:true});new MutationObserver(update).observe(extraPreview,{childList:true});mainInput.addEventListener('change',()=>{const previous=mainPreview.querySelector('figure');if(mainInput.value!=='0'&&previous&&previous.dataset.imageId!==mainInput.value&&![...extraPreview.querySelectorAll('figure')].some(n=>n.dataset.imageId===previous.dataset.imageId)){extraPreview.prepend(previous.cloneNode(true));}update();});extraInput.addEventListener('change',update);update();
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
