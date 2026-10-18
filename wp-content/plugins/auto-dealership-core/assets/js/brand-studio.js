(function(){
    'use strict';
    const hex=rgb=>'#'+rgb.map(v=>Math.max(0,Math.min(255,Math.round(v))).toString(16).padStart(2,'0')).join('').toUpperCase();
    function extract(pixels){
        const bins=new Map();
        for(let i=0;i<pixels.length;i+=4){if(pixels[i+3]<32)continue;const rgb=Array.from(pixels.slice(i,i+3)),weight=pixels[i+3]/255,key=rgb.map(v=>Math.floor(v/24)).join(',');let b=bins.get(key);if(!b){b={sum:[0,0,0],n:0};bins.set(key,b);}b.n+=weight;rgb.forEach((v,j)=>b.sum[j]+=v*weight);}
        const all=[...bins.values()].map(b=>({rgb:b.sum.map(v=>v/b.n),n:b.n}));
        const chromatic=all.filter(b=>Math.max(...b.rgb)-Math.min(...b.rgb)>35&&Math.max(...b.rgb)>75&&Math.min(...b.rgb)<225);
        // Favor representative visible brand colors over nearly black shadows and tiny bright highlights.
        const score=b=>b.n*Math.pow(Math.max(...b.rgb)/255,.8)*(1-Math.min(...b.rgb)/300);
        const sorted=(chromatic.length?chromatic:all).sort((a,b)=>score(b)-score(a)),result=[];
        for(const b of sorted){if(result.every(c=>Math.hypot(...b.rgb.map((v,j)=>v-c[j]))>65))result.push(b.rgb);if(result.length===6)break;}
        return result.map(hex);
    }
    function select(colors){
        const primary=colors[0],rgb=c=>[1,3,5].map(i=>parseInt(c.slice(i,i+2),16)),p=rgb(primary);
        const candidates=colors.slice(1).filter(c=>{const v=rgb(c),max=Math.max(...v);return max>0&&(max-Math.min(...v))/max>.65&&max>=Math.max(...p);});
        candidates.sort((a,b)=>Math.hypot(...rgb(b).map((v,i)=>v-p[i]))-Math.hypot(...rgb(a).map((v,i)=>v-p[i])));
        return{primary,accent:candidates[0]||colors[1]||primary};
    }
    function generate(engine,base,primary,accent,style,preserve,options={}){
        const source=window.adcColorLab?adcColorLab.prepare(base,options.meta,preserve):(preserve?window.adcBrandColors.canonical(base):{format:1,light:{},dark:{}});
        base=source;const next=window.adcBrandColors.canonical(source);
        if(window.adcColorLab)engine={...engine,mix:adcColorLab.mix};
        const navy=options.navy||engine.mix(primary,'#080F1C',.82);
        Object.assign(next.light,{'brand-blue':primary,'brand-red':accent,'brand-navy':navy});
        const assign=(key,value)=>{if(!preserve||!Object.hasOwn(base.light||{},key))next.light[key]=value;};
        const dark=(key,value)=>{if(!preserve||!Object.hasOwn(base.dark||{},key))next.dark[key]=value;};
        const readable=(color,bg)=>{for(let i=0;i<=100;i++){const c=engine.mix(color,'#000000',i/100);if(engine.contrast(c,bg)>=4.5)return c;}return '#000000';};
        assign('action',readable(primary,'#FFFFFF'));assign('action-hover',engine.mix(next.light.action,'#000000',.16));
        assign('control-border',engine.mix(navy,'#FFFFFF',.38));
        assign('soft',engine.mix(primary,'#FFFFFF',.96));
        dark('bg',engine.mix(primary,'#080C12',.96));dark('surface',engine.mix(primary,'#101820',.91));dark('soft',engine.mix(primary,'#17232E',.84));
        dark('action',engine.mix(primary,'#FFFFFF',.38));dark('action-hover',engine.mix(next.dark.action,'#FFFFFF',.12));
        dark('link',engine.mix(primary,'#FFFFFF',.58));dark('focus',next.dark.link);dark('info',next.dark.link);dark('info-bg',engine.mix(primary,'#111A25',.85));
        dark('offer',engine.mix(accent,'#FFFFFF',.38));dark('offer-hover',engine.mix(next.dark.offer,'#FFFFFF',.12));
        dark('border',engine.mix(primary,'#9CAABA',.50));dark('control-border',engine.mix(primary,'#C0CAD5',.65));
        dark('on-dark-link',next.dark.link);
        if(window.adcColorLab){
            const tone=(color,angle,L,C=1)=>adcColorLab.harmony(color,angle,L,C),p=(L,C=1)=>tone(primary,0,L,C),a=(L,C=1)=>tone(accent,0,L,C),near=(L,C=1)=>tone(primary,-35,L,C),warm=(L,C=.6)=>tone(primary,180,L,C),side=(L,C=.6)=>tone(primary,150,L,C),third=(L,C=.55)=>tone(primary,240,L,C),muted=(L)=>p(L,.09);
            const profiles={
                corporate:{light:{},dark:{}},
                luxury:{light:{header:engine.mix(navy,'#000000',.2),'header-text':'#FFFFFF','header-active':warm(.82,.35),soft:engine.mix(warm(.7),'#FFFFFF',.94),action:navy,offer:warm(.52,.45),hero:engine.mix(navy,'#000000',.15),'hero-end':engine.mix(navy,warm(.22,.25),.25),footer:engine.mix(navy,'#000000',.35)},dark:{bg:p(.09,.2),surface:p(.14,.2),soft:p(.20,.25),header:p(.12,.2),action:warm(.80,.4),offer:p(.75,.6),hero:p(.13,.3),'hero-end':warm(.19,.18),footer:p(.07,.2)}},
                modern:{light:{bg:engine.mix(near(.6),'#FFFFFF',.985),header:engine.mix(near(.6),'#FFFFFF',.92),soft:engine.mix(near(.6),'#FFFFFF',.87),action:readable(near(.56),'#FFFFFF'),offer:tone(primary,25,.53,.75),hero:near(.20,.6),'hero-end':engine.mix(near(.24,.7),p(.3,.6),.3),footer:near(.15,.5)},dark:{bg:near(.1,.3),surface:near(.17,.4),soft:near(.24,.5),header:near(.20,.45),action:near(.77,.8),offer:tone(primary,25,.78,.7),hero:near(.15,.5),'hero-end':p(.23,.5),footer:near(.08,.25)}},
                bold:{light:{bg:engine.mix(accent,'#FFFFFF',.99),soft:engine.mix(accent,'#FFFFFF',.9),action:readable(accent,'#FFFFFF'),offer:readable(primary,'#FFFFFF'),hero:a(.19,.6),'hero-end':engine.mix(a(.25,.7),navy,.25),footer:navy},dark:{bg:a(.1,.3),surface:a(.17,.4),soft:a(.24,.55),header:a(.18,.4),action:a(.78,.8),offer:p(.76,.8),hero:a(.15,.5),'hero-end':a(.23,.6),footer:a(.08,.25)}},
                neutral:{light:{bg:muted(.99),header:muted(.96),soft:muted(.9),action:muted(.28),offer:a(.50,.4),hero:muted(.23),'hero-end':muted(.3),footer:muted(.15)},dark:{bg:muted(.1),surface:muted(.17),soft:muted(.24),header:muted(.2),action:p(.80,.22),offer:a(.78,.3),hero:muted(.16),'hero-end':muted(.25),footer:muted(.08)}},
                complementary:{light:{bg:engine.mix(warm(.6),'#FFFFFF',.985),header:engine.mix(warm(.6),'#FFFFFF',.92),soft:engine.mix(warm(.6),'#FFFFFF',.85),action:readable(primary,'#FFFFFF'),offer:readable(warm(.57),'#FFFFFF'),hero:navy,'hero-end':engine.mix(navy,warm(.25,.3),.22),footer:warm(.16,.3)},dark:{bg:warm(.1,.18),surface:warm(.17,.25),soft:warm(.24,.3),header:warm(.20,.3),action:p(.78,.75),offer:warm(.79,.55),hero:p(.15,.4),'hero-end':warm(.23,.3),footer:warm(.08,.2)}},
                split:{light:{bg:'#FFFFFF',header:engine.mix(tone(primary,30,.6,.8),'#FFFFFF',.9),soft:engine.mix(side(.6),'#FFFFFF',.85),action:readable(tone(primary,30,.55,.8),'#FFFFFF'),offer:readable(side(.57),'#FFFFFF'),hero:tone(primary,30,.20,.5),'hero-end':side(.26,.3),footer:tone(primary,30,.14,.4)},dark:{bg:tone(primary,30,.1,.25),surface:tone(primary,30,.17,.35),soft:side(.24,.25),header:tone(primary,30,.2,.4),action:tone(primary,30,.78,.75),offer:side(.79,.6),hero:tone(primary,30,.15,.4),'hero-end':side(.23,.3),footer:tone(primary,30,.08,.2)}},
                triadic:{light:{bg:engine.mix(third(.6),'#FFFFFF',.99),header:engine.mix(third(.6),'#FFFFFF',.93),soft:engine.mix(third(.6),'#FFFFFF',.87),action:readable(p(.53,.8),'#FFFFFF'),offer:readable(tone(primary,120,.57,.6),'#FFFFFF'),hero:third(.20,.4),'hero-end':third(.28,.5),footer:third(.14,.3)},dark:{bg:third(.1,.2),surface:third(.17,.3),soft:third(.24,.4),header:third(.20,.35),action:p(.80,.7),offer:tone(primary,120,.79,.6),hero:third(.15,.3),'hero-end':third(.24,.45),footer:third(.08,.2)}}
            };
            const profile=profiles[style]||profiles.corporate;
            for(const [key,value] of Object.entries(profile.light))assign(key,value);
            for(const [key,value] of Object.entries(profile.dark))dark(key,value);
            assign('action-hover',engine.mix(next.light.action,'#000000',.16));dark('action-hover',engine.mix(next.dark.action,'#FFFFFF',.12));
            assign('offer-hover',engine.mix(next.light.offer||accent,'#000000',.15));dark('offer-hover',engine.mix(next.dark.offer,'#FFFFFF',.12));
            dark('link',engine.mix(next.dark.action,'#FFFFFF',.2));dark('focus',next.dark.link);dark('info',next.dark.link);
            assign('primary',next.light.hero||navy);assign('primary-hover',engine.mix(next.light.primary,'#FFFFFF',.12));
        }
        if(window.adcColorLab)for(const [family,anchor] of Object.entries({blue:primary,red:accent,slate:navy}))for(const [step,amount] of Object.entries({50:.94,100:.88,200:.75,300:.58,400:.30,500:0,600:-.12,700:-.28,800:-.45,900:-.62,950:-.77})){
            const key=family+'-'+step,value=engine.mix(anchor,amount>=0?'#FFFFFF':'#000000',Math.abs(amount));assign(key,value);dark(key,value);
        }
        // Repair generated values only; preserve explicitly customized semantic colors.
        for(const mode of ['light','dark'])for(let pass=0;pass<4;pass++){
            const colors=engine.resolve(next,mode);
            for(const fg of new Set(engine.pairs.map(p=>p[0]))){
                if(preserve&&Object.hasOwn(base[mode]||{},fg))continue;
                const pairs=engine.pairs.filter(p=>p[0]===fg);
                if(pairs.every(([,bg,min])=>engine.contrast(colors[fg],colors[bg])>=min))continue;
                const target=['#FFFFFF','#000000'].sort((a,b)=>Math.min(...pairs.map(([,bg])=>engine.contrast(b,colors[bg])))-Math.min(...pairs.map(([,bg])=>engine.contrast(a,colors[bg]))))[0];
                for(let step=1;step<=100;step++){const candidate=engine.mix(colors[fg],target,step/100);if(pairs.every(([,bg,min])=>engine.contrast(candidate,colors[bg])>=min)){next[mode][fg]=candidate;break;}}
            }
        }
        return next;
    }
    window.adcBrandStudio={extract,select,generate};
    if(!window.wp||!wp.customize)return;
    wp.customize.bind('ready',()=>{
        const host=document.querySelector('[data-palette-group=overview]'),setting=wp.customize('adc_brand_palette'),data=window.adcBrandCustomizer;
        if(!host||!setting||!data)return;
        const engine=adcBrandColors.create(data.schema),metaSetting=wp.customize('adc_brand_palette_meta'),ar=document.documentElement.lang.startsWith('ar'),t=(a,e)=>ar?a:e;
        const meta=()=>metaSetting?JSON.parse(JSON.stringify(metaSetting.get())):{format:1,generated:{light:{},dark:{}},extracted:[],roles:{}};
        let extracted=meta().extracted||[];
        const el=(tag,text)=>{const n=document.createElement(tag);if(text)n.textContent=text;return n;};
        const box=el('section');box.className='adc-brand-studio';box.append(el('h3',t('استوديو الهوية اللونية','Color identity studio')),el('p',t('حلّل الشعار واختر هوية. تظل التغييرات خاصة حتى النشر.','Analyze your logo and choose an identity. Changes remain private until Publish.')));
        const analyze=el('button',t('تحليل شعار الموقع','Analyze site logo'));analyze.type='button';analyze.className='button';
        const file=el('input');file.type='file';file.accept='image/png,image/jpeg,image/webp';file.setAttribute('aria-label',t('تحليل صورة شعار من جهازك دون رفعها','Analyze a local logo without uploading it'));
        const status=el('p');status.setAttribute('role','status');const controls=el('div'),cards=el('fieldset');cards.className='adc-studio-cards adc-studio-schemes';
        const primary=el('input'),accent=el('input'),initial=engine.resolve(adcBrandColors.canonical(setting.get()),'light');primary.type=accent.type='color';primary.value=initial['brand-blue'];accent.value=initial['brand-red'];
        const navy=el('input');navy.type='color';navy.value=initial['brand-navy'];primary.dataset.studioRole='primary';accent.dataset.studioRole='accent';navy.dataset.studioRole='navy';
        const swap=el('button',t('تبديل لون التفاعل والعروض','Swap interaction and offers'));swap.type='button';swap.className='button';swap.dataset.studioSwap='';swap.onclick=()=>{const value=primary.value;primary.value=accent.value;accent.value=value;render();};
        const keep=el('input');keep.type='checkbox';keep.checked=false;
        for(const [input,label] of [[primary,t('لون التفاعل والأزرار','Interaction and buttons')],[accent,t('لون العروض والعناصر البارزة','Offers and accents')],[navy,t('اللون الداكن للهيرو والتذييل','Deep color for hero and footer')],[keep,t('الحفاظ على الألوان اليدوية وتحديث الألوان المولدة','Preserve manual colors and refresh generated colors')]]){const l=el('label',label);l.append(input);if(input.dataset.studioRole){const code=el('code',input.value.toUpperCase());code.dataset.roleHex=input.dataset.studioRole;l.append(code);}controls.append(l);}controls.append(swap);
        let original=null,originalMeta=null,serial=0,selectionBase=null,selectionMeta=null,applying=false;
        setting.bind(()=>{if(!applying){selectionBase=null;selectionMeta=null;const c=engine.resolve(adcBrandColors.canonical(setting.get()),'light');primary.value=c['brand-blue'];accent.value=c['brand-red'];navy.value=c['brand-navy'];extracted=meta().extracted||[];}render();});
        const undo=el('button',t('التراجع عن آخر هوية','Undo last identity'));undo.type='button';undo.className='button';undo.disabled=true;undo.onclick=()=>{if(original){if(metaSetting)metaSetting.set(originalMeta);setting.set(adcBrandColors.canonical(original));original=null;undo.disabled=true;status.textContent=t('تم التراجع.','Identity reverted.');}};
        function apply(style,preserve){
            original=adcBrandColors.canonical(setting.get());originalMeta=meta();if(!selectionBase){selectionBase=adcBrandColors.canonical(original);selectionMeta=originalMeta;}
            const base=selectionBase,old=selectionMeta,value=generate(engine,base,primary.value,accent.value,style,preserve,{meta:old,navy:navy.value});
            applying=true;try{if(metaSetting&&window.adcColorLab)metaSetting.set(adcColorLab.metadata(value,base,old,extracted,{primary:primary.value.toUpperCase(),accent:accent.value.toUpperCase(),navy:navy.value.toUpperCase()},preserve,style));setting.set(value);}finally{applying=false;}
            undo.disabled=false;
        }
        function render(){
            const focused=document.activeElement?.name==='adc-logo-scheme'?document.activeElement.value:null;
            const mode=document.querySelector('[data-customizer-mode]')?.value||'light',current=engine.resolve(adcBrandColors.canonical(setting.get()),'light');
            const matching=current['brand-blue'].toUpperCase()===primary.value.toUpperCase()&&current['brand-red'].toUpperCase()===accent.value.toUpperCase()&&current['brand-navy'].toUpperCase()===navy.value.toUpperCase();
            controls.querySelectorAll('[data-role-hex]').forEach(code=>{code.textContent=controls.querySelector('[data-studio-role="'+code.dataset.roleHex+'"]').value.toUpperCase();});cards.replaceChildren();
            cards.append(el('legend',t('مخططات لونية من شعارك','Color schemes from your logo')),el('p',t('هويات متنوعة من ألوان الشعار ودرجات منسجمة معها. الاختيار يعرض المخطط كاملًا في المعاينة فقط؛ ويمكنك تفعيل الحفاظ على الألوان اليدوية أعلاه. انشر لاعتماد التغيير.','Varied identities use your logo colors and harmonized shades. Select a complete scheme for private preview, or enable preservation of manual colors above. Publish to apply.')));
            const signatures=new Set();
            for(const [id,a,e,arNote,enNote] of [
                ['corporate','مؤسسية واضحة','Corporate','أسطح فاتحة وتفاعل بلون الهوية','Clean surfaces and brand interactions'],
                ['luxury','فاخرة هادئة','Luxury','درجات داكنة ولمسات دافئة مكملة للشعار','Deep tones with warm complementary accents'],
                ['modern','انسجام متجاور','Analogous harmony','ألوان مجاورة للهوية مع أسطح ملونة هادئة','Neighboring brand hues with softly tinted surfaces'],
                ['bold','ألوان متبادلة','Swapped accents','اللون الثانوي يقود التفاعل وخلفيات الهوية','The secondary color leads interactions and backgrounds'],
                ['neutral','جرافيت هادئ','Quiet graphite','درجات محايدة بلمسات خفيفة من الشعار','Graphite surfaces with restrained logo accents'],
                ['complementary','تباين متوازن','Balanced contrast','الهوية الأساسية مع لون مقابل بلمسات محدودة','Brand colors with a restrained complementary hue'],
                ['split','توازن متشعب','Split harmony','تفاعل بلون قريب وعروض بلون متباين متناغم','A neighboring action hue and a harmonious contrast accent'],
                ['triadic','تناغم ثلاثي','Triadic harmony','ثلاث عائلات لونية موزعة بتوازن حول الهوية','Three balanced color families derived from the brand']]){
                const value=generate(engine,selectionBase||adcBrandColors.canonical(setting.get()),primary.value,accent.value,id,keep.checked,{meta:selectionMeta||meta(),navy:navy.value}),colors=engine.resolve(value,mode);
                const tile=el('label');tile.className='adc-studio-card adc-studio-scheme';
                const row=el('span');row.className='adc-scheme-heading';const radio=el('input');radio.type='radio';radio.name='adc-logo-scheme';radio.value=id;radio.dataset.identity=id;radio.checked=matching&&meta().style===id;
                const title=el('strong',t(a,e));row.append(radio,title);tile.append(row);
                const strip=el('span');strip.className='adc-scheme-strip';strip.setAttribute('aria-hidden','true');
                const colorsText=[];
                for(const [key,label] of [['header',t('القائمة','Header')],['action',t('التفاعل','Action')],['offer',t('العروض','Offers')],['soft',t('الأقسام','Sections')],['footer',t('التذييل','Footer')]]){const swatch=el('span');swatch.style.backgroundColor=colors[key];swatch.title=label+' '+colors[key];strip.append(swatch);colorsText.push(label+' '+colors[key]);}
                signatures.add(colorsText.join('|'));
                const note=el('small',t(arNote,enNote));note.id='adc-scheme-note-'+id;const accessible=el('span',colorsText.join(' · '));accessible.className='screen-reader-text';accessible.id='adc-scheme-colors-'+id;radio.setAttribute('aria-describedby',note.id+' '+accessible.id);tile.append(strip,note,accessible);
                radio.addEventListener('change',()=>{if(!radio.checked)return;apply(id,keep.checked);status.textContent=t('تمت معاينة مخطط «','Previewing “')+t(a,e)+t('». انشر لاعتماده، أو اختر مخططًا آخر للمقارنة.','”. Publish to apply, or choose another scheme to compare.');});cards.append(tile);
            }
            if(keep.checked&&signatures.size<5){const note=el('p',t('بعض المخططات متشابهة بسبب الحفاظ على ألوانك اليدوية.','Some schemes look alike because your manual colors are preserved.'));const full=el('button',t('عرض المخططات كاملة','Show complete schemes'));full.type='button';full.className='button';full.onclick=()=>{keep.checked=false;render();status.textContent=t('تُعرض الآن المخططات كاملة. اختر مخططًا لتطبيقه في المعاينة، ويمكنك التراجع.','Complete schemes are shown. Choose one to apply it in preview; you can undo it.');};note.append(full);cards.append(note);}
            if(focused)cards.querySelector('[data-identity="'+focused+'"]')?.focus({preventScroll:true});
        }
        document.addEventListener('change',event=>{if(event.target.matches('[data-customizer-mode],[data-palette-footer-mode]'))render();});
        const reset=el('button',t('إعادة ضبط الهوية من الشعار الحالي','Reset identity from current logo'));reset.type='button';reset.className='button';reset.dataset.studioReset='';
        async function load(url){
            const ticket=++serial;let timer;analyze.disabled=reset.disabled=true;status.textContent=t('جارٍ تحليل الشعار…','Analyzing logo…');
            try{
                if(!url)throw Error('missing');const image=new Image();image.crossOrigin='anonymous';image.src=url;
                await Promise.race([image.decode(),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('timeout')),12000);})]);
                if(ticket!==serial)return;if(!image.naturalWidth)throw Error('empty');
                const canvas=document.createElement('canvas'),scale=Math.min(1,256/Math.max(image.naturalWidth,image.naturalHeight));canvas.width=Math.max(1,Math.round(image.naturalWidth*scale));canvas.height=Math.max(1,Math.round(image.naturalHeight*scale));
                const ctx=canvas.getContext('2d',{willReadFrequently:true});ctx.drawImage(image,0,0,canvas.width,canvas.height);
                const colors=extract(ctx.getImageData(0,0,canvas.width,canvas.height).data);if(!colors.length)throw Error('empty');
                const chosen=select(colors);extracted=colors;primary.value=chosen.primary;accent.value=chosen.accent;navy.value=window.adcColorLab?adcColorLab.mix(chosen.primary,'#080F1C',.82):engine.mix(chosen.primary,'#080F1C',.82);
                swatches.replaceChildren();colors.forEach(color=>{const b=el('button',color);b.type='button';b.style.borderBottom='8px solid '+color;b.onclick=()=>{primary.value=color;render();};swatches.append(b);});render();
                status.textContent=t('تم استخراج الألوان. يمكنك تعديل اللونين قبل اختيار الهوية.','Colors extracted. Adjust primary and accent before choosing an identity.');return chosen;
            }catch(error){if(ticket===serial)status.textContent=t('تعذر تحليل الصورة. استخدم PNG أو JPG أو WebP من جهازك، أو اختر الألوان يدويًا. لم تتغير إعدادات الموقع.','Could not analyze image. Choose a local PNG, JPG or WebP, or select colors manually. Site settings are unchanged.');}
            finally{clearTimeout(timer);if(ticket===serial)analyze.disabled=reset.disabled=false;}
        }
        const swatches=el('div');swatches.className='adc-studio-swatches';
        async function currentLogo(){
            const logo=wp.customize('custom_logo'),id=logo?Number(logo()):0;
            if(logo&&!id)return '';
            if(id&&window.wp.media){try{const attachment=wp.media.attachment(id);await attachment.fetch();return id===Number(logo())?attachment.get('url'):'';}catch(error){/* Same-origin server fallback only for the originally published logo. */if(id!==Number(window.adcBrandStudioLogoId))return '';}}
            return window.adcBrandStudioLogo;
        }
        async function analyzeCurrent(replace){
            analyze.disabled=reset.disabled=true;
            const url=await currentLogo();if(!url){analyze.disabled=reset.disabled=false;status.textContent=t('أضف شعار الموقع أولًا أو اختر صورة من جهازك.','Set a site logo or choose a local image.');return;}
            const colors=await load(url);if(!colors||!replace)return;
            selectionBase=null;selectionMeta=null;keep.checked=false;apply('corporate',false);render();
            status.textContent=t('أعيد توليد الهوية كاملة من الشعار الحالي في المعاينة. يمكنك التراجع أو النشر لاعتمادها.','Complete identity regenerated from the current logo in preview. Undo or Publish to apply.');
        }
        analyze.onclick=()=>analyzeCurrent(false);reset.onclick=()=>analyzeCurrent(true);
        file.onchange=async()=>{const f=file.files[0];if(!f)return;if(!['image/png','image/jpeg','image/webp'].includes(f.type)||f.size>10*1024*1024){status.textContent=t('اختر صورة PNG أو JPG أو WebP بحجم لا يتجاوز 10 ميجابايت.','Choose a PNG, JPG or WebP up to 10 MB.');return;}const url=URL.createObjectURL(f);try{await load(url);}finally{URL.revokeObjectURL(url);file.value='';}};
        [primary,accent,navy,keep].forEach(n=>n.addEventListener('change',render));const logo=wp.customize('custom_logo');if(logo)logo.bind(()=>{status.textContent=t('تغيّر الشعار؛ اضغط تحليل لتحديث الاقتراحات دون تغيير ألوان الموقع تلقائيًا.','Logo changed. Analyze again to refresh suggestions; published colors remain unchanged.');});
        box.append(analyze,reset,file,status,swatches,controls,cards,undo);host.prepend(box);render();
    });
})();
