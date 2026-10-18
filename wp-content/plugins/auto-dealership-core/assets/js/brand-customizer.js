(function(api){
    'use strict';
    const data=window.adcBrandCustomizer;if(!data||!window.adcBrandColors)return;
    api.bind('ready',function(){
        const setting=api('adc_brand_palette'),engine=adcBrandColors.create(data.schema),s=data.strings;
        if(!setting)return;
        let mode='light',advanced=false;
        const config=()=>adcBrandColors.canonical(setting.get());
        const change=fn=>{const next=config();fn(next);setting.set(next);};
        const node=(tag,text,cls)=>{const el=document.createElement(tag);if(text)el.textContent=text;if(cls)el.className=cls;return el;};
        function sendMode(){document.querySelectorAll('[data-customizer-mode],[data-palette-footer-mode]').forEach(el=>el.value=mode);api.previewer.send('adc-palette-mode',mode);}
        function draw(){
            const value=config(),colors=engine.resolve(value,mode);
            document.querySelectorAll('[data-palette-group]').forEach(container=>{
                const group=container.dataset.paletteGroup;
                if(group==='overview')return;
                container.replaceChildren();
                if(group==='mail'){container.append(node('p',s.mailNote));const preview=node('button',s.mail,'button');preview.type='button';preview.dataset.mailPreview='';preview.addEventListener('click',()=>{mode='light';document.querySelector('[data-customizer-mode]').value=mode;api.previewer.previewUrl.set(data.mail);sendMode();draw();});container.append(preview);}
                const scheme=group==='mail'?'light':mode,values=engine.resolve(value,scheme);
                Object.entries(data.schema).filter(([,spec])=>spec.group===group).forEach(([key,spec])=>{
                    const basic=['brand-navy','brand-blue','brand-red','bg','surface','soft','text','heading','muted'].includes(key);
                    if(!advanced&&group==='brand'&&!basic)return;
                    const row=node('div',null,'adc-customizer-color');row.dataset.token=key;
                    const label=node('label',spec.label);label.htmlFor='adc-customizer-'+key;
                    const inputs=node('div',null,'adc-customizer-color-inputs');
                    const picker=node('input');picker.type='color';picker.id=label.htmlFor;picker.value=values[key];picker.setAttribute('aria-label',spec.label);
                    const hex=node('input');hex.type='text';hex.dir='ltr';hex.value=values[key];hex.maxLength=7;hex.setAttribute('aria-label',spec.label+' HEX');
                    const automatic=node('button',s.automatic,'button-link');automatic.type='button';automatic.disabled=!Object.hasOwn(value[scheme],key);automatic.setAttribute('aria-label',spec.label+' '+s.automatic);
                    const status=node('small',automatic.disabled?s.automatic:s.custom);
                    function update(v){if(!/^#[a-f0-9]{6}$/i.test(v))return;change(next=>{next[scheme][key]=v.toUpperCase();});}
                    picker.addEventListener('input',()=>update(picker.value));hex.addEventListener('input',()=>update(hex.value));hex.addEventListener('blur',()=>{hex.value=engine.resolve(config(),scheme)[key];});
                    automatic.addEventListener('click',()=>change(next=>{delete next[scheme][key];}));
                    inputs.append(picker,hex,automatic,status);row.append(label,inputs);container.append(row);
                });
                const reset=node('button',s.reset,'button');reset.type='button';reset.addEventListener('click',()=>change(next=>{Object.entries(data.schema).forEach(([key,spec])=>{if(spec.group===group)delete next[scheme][key];});}));container.append(reset);
            });
            drawContrast();
        }
        function sync(){
            const value=config();document.querySelectorAll('[data-palette-group] [data-token]').forEach(row=>{
                const key=row.dataset.token,scheme=data.schema[key].group==='mail'?'light':mode,color=engine.resolve(value,scheme)[key];
                row.querySelector('input[type=color]').value=color;const hex=row.querySelector('input[type=text]');if(document.activeElement!==hex)hex.value=color;
                const auto=row.querySelector('button');auto.disabled=!Object.hasOwn(value[scheme],key);row.querySelector('small').textContent=auto.disabled?s.automatic:s.custom;
            });drawContrast();
        }
        function drawContrast(){
            const box=document.querySelector('[data-customizer-contrast]');if(!box)return;box.replaceChildren();const value=config();
            for(const scheme of ['light','dark']){const colors=engine.resolve(value,scheme),bad=engine.pairs.filter(([fg,bg,min])=>engine.contrast(colors[fg],colors[bg])<min);const details=node('details'),summary=node('summary',(scheme==='light'?s.light:s.dark)+' — '+(bad.length?s.fail+' ('+bad.length+')':s.pass));details.open=bad.length>0;details.dataset.failures=bad.length;details.dataset.scheme=scheme;details.append(summary);
                bad.forEach(([fg,bg,min])=>{const row=node('p',data.schema[fg].label+' / '+data.schema[bg].label+': '+engine.contrast(colors[fg],colors[bg]).toFixed(2)+':1 ('+min+':1) ');const fix=node('button',s.fix,'button-link');fix.type='button';fix.addEventListener('click',()=>{const matches=engine.pairs.filter(([key])=>key===fg),backgrounds=matches.map(([,key])=>colors[key]),target=Math.min(...backgrounds.map(bg=>engine.contrast('#FFFFFF',bg)))>=Math.min(...backgrounds.map(bg=>engine.contrast('#000000',bg)))?'#FFFFFF':'#000000';let result=target;for(let i=1;i<=100;i++){const candidate=engine.mix(colors[fg],target,i/100);if(matches.every(([,key,limit])=>engine.contrast(candidate,colors[key])>=limit)){result=candidate;break;}}change(next=>{next[scheme][fg]=result;});});row.append(fix);details.append(row);});box.append(details);
            }
        }
        const overview=document.querySelector('[data-palette-group=overview]');if(!overview)return;
        overview.append(node('p',s.draft));const label=node('label',s.mode);const select=node('select');select.dataset.customizerMode='';['light','dark'].forEach(value=>{const option=node('option',s[value]);option.value=value;select.append(option);});label.append(select);overview.append(label);select.addEventListener('change',()=>{mode=select.value;sendMode();draw();});
        const advancedLabel=node('label',null,'adc-customizer-advanced'),checkbox=node('input');checkbox.type='checkbox';checkbox.addEventListener('change',()=>{advanced=checkbox.checked;draw();});advancedLabel.append(checkbox,document.createTextNode(s.advanced));overview.append(advancedLabel);
        const contrasts=node('div');contrasts.dataset.customizerContrast='';contrasts.setAttribute('aria-live','polite');overview.append(node('h3',s.contrast),contrasts);
        [[data.tools,s.tools],[data.admin,s.admin]].forEach(([href,text])=>{const p=node('p'),a=node('a',text);a.href=href;a.target='_blank';a.rel='noopener';p.append(a);overview.append(p);});
        const footer=document.querySelector('.wp-full-overlay-footer');if(footer){const wrapper=node('label',s.mode,'adc-palette-footer-mode'),footerSelect=select.cloneNode(true);delete footerSelect.dataset.customizerMode;footerSelect.dataset.paletteFooterMode='';wrapper.append(footerSelect);footerSelect.addEventListener('change',()=>{mode=footerSelect.value;sendMode();draw();});footer.prepend(wrapper);}
        setting.bind(sync);api.previewer.bind('ready',sendMode);api.previewer.bind('synced',sendMode);draw();sendMode();
    });
})(wp.customize);
