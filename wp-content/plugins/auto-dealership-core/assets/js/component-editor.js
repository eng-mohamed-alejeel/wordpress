(function () {
  'use strict';
  const config = window.adcComponentEditor;
  if (!config || !window.wp) return;
  const t = (ar, en) => config.ar ? ar : en;
  const items = config.items;
  const groups = {identity:t('الهوية','Identity'),vehicles:t('السيارات والأدوات','Vehicles and tools'),contact:t('التواصل والفروع','Contact and branches'),about:t('التعريف بالمعرض','About the dealership'),legal:t('السياسات','Policies'),legacy:t('أقسام مشروطة بالمحتوى','Content-dependent sections')};
  const code = item => '[' + item.tag + (item.parameters.includes('count') ? ' count="6"' : '') + (item.parameters.includes('price') ? ' price="0"' : '') + ']';
  let lastTranslation = null;
  document.addEventListener('focusin', event => { if (event.target.matches('textarea[name="adc_content_en"]')) lastTranslation = event.target; });
  function insertTranslation(value) {
    const target = lastTranslation || document.querySelector('textarea[name="adc_content_en"]');
    if (!target) return false;
    const from = target.selectionStart ?? target.value.length, to = target.selectionEnd ?? from;
    target.setRangeText(value, from, to, 'end'); target.dispatchEvent(new Event('input', {bubbles:true})); target.dispatchEvent(new Event('change', {bubbles:true})); target.focus(); return true;
  }
  const el = wp.element.createElement;
  const {Button, TextControl, SelectControl, ToggleControl, PanelBody, Placeholder} = wp.components;
  const SSR = wp.serverSideRender?.ServerSideRender || wp.serverSideRender?.default || wp.serverSideRender;
  function Library() {
    const [query, setQuery] = wp.element.useState('');
    const [message, setMessage] = wp.element.useState('');
    return el('div', {className:'adc-component-library'},
      el(TextControl, {label:t('ابحث عن مكوّن','Find a component'), value:query, onChange:setQuery}),
      el('p', {className:'adc-editor-help'}, t('أضف المكوّن ثم حرّكه أو عدّل إعداداته من شريط البلوكات. تظهر البيانات حسب لغة الزائر.','Insert a component, then move it or change its block settings. Public data follows the visitor’s language.')),
      el('div', {className:'adc-library-results'}, items.filter(item => (item.title + item.description + item.tag).toLowerCase().includes(query.toLowerCase())).map(item => el('article', {className:'adc-library-item',key:item.tag},
        el('small',null,groups[item.group]),el('h3',null,item.title),el('p',null,item.description),item.availability && el('p',null,item.availability),el('a',{href:item.manageUrl,target:'_blank',rel:'noopener'},t('إدارة البيانات والجاهزية','Manage data and readiness')),el('code',null,code(item)),
        el('div',{className:'adc-library-actions'},
          el(Button,{variant:'primary',onClick:()=>{wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('auto-dealership/component',{component:item.tag}));setMessage(t('تمت إضافة المكوّن.','Component inserted.'));}},t('إضافة للصفحة','Insert block')),
          el(Button,{variant:'secondary',onClick:()=>{if(insertTranslation(code(item)))setMessage(t('أضيف الكود للترجمة الإنجليزية.','Inserted into the English translation.'));else setMessage(t('افتح حقل الترجمة الإنجليزية أولًا.','Open the English translation field first.'));}},t('للترجمة','To translation')),
          el(Button,{variant:'tertiary',onClick:()=>{navigator.clipboard?.writeText(code(item)).then(()=>setMessage(t('تم نسخ الكود.','Shortcode copied.'))).catch(()=>setMessage(code(item)));}},t('نسخ الكود','Copy shortcode'))
        )
      ))),el('p',{role:'status'},message));
  }
  if (wp.blocks && !wp.blocks.getBlockType('auto-dealership/component')) {
    wp.blocks.registerBlockType('auto-dealership/component', {
      apiVersion:3,title:t('مكوّن من المعرض','Dealership component'),description:t('مكوّن ديناميكي من النظام مع معاينة وإعدادات.','A dynamic platform component with preview and settings.'),icon:'store',category:'adc-platform',
      attributes:{component:{type:'string',default:'adc_brand_name'},count:{type:'integer',default:6},price:{type:'number',default:0},previewLanguage:{type:'string',default:''}},supports:{html:false,align:['wide','full']},
      edit: function(props) {
        const {attributes:a,setAttributes} = props;
        const item = items.find(item=>item.tag===a.component) || items[0];
        const [preview,setPreview] = wp.element.useState(true);
        const [language,setLanguage] = wp.element.useState(config.ar?'ar':'en');
        const controls = el(PanelBody,{title:t('إعدادات المكوّن','Component settings'),initialOpen:true},
          el(SelectControl,{label:t('المكوّن','Component'),value:a.component,options:items.map(i=>({label:groups[i.group]+' · '+i.title,value:i.tag})),onChange:value=>setAttributes({component:value})}),
          item.parameters.includes('count') && el(TextControl,{label:t('عدد العناصر (1–48)','Item count (1–48)'),type:'number',min:1,max:48,value:a.count,onChange:value=>setAttributes({count:Math.min(48,Math.max(1,parseInt(value,10)||1))})}),
          item.parameters.includes('price') && el(TextControl,{label:t('السعر الابتدائي بالريال','Initial price in SAR'),type:'number',min:0,max:100000000,value:a.price,onChange:value=>setAttributes({price:Math.min(100000000,Math.max(0,Number(value)||0))})}),
          el(ToggleControl,{label:t('عرض المعاينة','Show preview'),checked:preview,onChange:setPreview}),
          el(SelectControl,{label:t('لغة المعاينة فقط','Preview language only'),value:language,options:[{label:'العربية',value:'ar'},{label:'English',value:'en'}],onChange:setLanguage}),
          el('p',null,item.description),item.availability && el('p',null,item.availability),el('a',{href:item.manageUrl,target:'_blank',rel:'noopener'},t('إدارة بيانات المكوّن','Manage component data')));
        return el('div',wp.blockEditor.useBlockProps({className:'adc-component-block'}),
          el(wp.blockEditor.InspectorControls,null,controls),
          el('div',{className:'adc-component-block-heading'},el('span',{className:'dashicons dashicons-layout','aria-hidden':true}),el('strong',null,item.title),el('code',null,'['+item.tag+']')),
          el('p',{className:'adc-editor-help'},item.description),
          preview && typeof SSR==='function' ? el('div',{className:'adc-component-preview',inert:'',dir:language==='ar'?'rtl':'ltr'},el(SSR,{block:'auto-dealership/component',attributes:{...a,previewLanguage:language},EmptyResponsePlaceholder:()=>el('p',null,t('لا يوجد محتوى متاح لهذا المكوّن حاليًا.','No content is currently available for this component.'))})) : el(Placeholder,{label:item.title},t('اختر المكوّن من الإعدادات.','Choose a component in settings.')),
          el('small',{className:'adc-editor-help'},t('المعاينة غير تفاعلية؛ اختبر النماذج والروابط من معاينة الصفحة.','Preview is non-interactive; use the page preview to test forms and links.')));
      },
      save:()=>null,
      variations:items.map(item=>({name:item.tag,title:item.title,description:item.description,attributes:{component:item.tag},isActive:['component'],scope:['inserter'],icon:item.group==='contact'?'location':item.group==='identity'?'admin-site-alt3':'layout'})),
      transforms:{from:[{type:'block',blocks:['core/shortcode'],isMatch:attrs=>/^\[([a-z_]+)(?:\s[^\]]*)?\]$/.test(attrs.text||'') && items.some(i=>(attrs.text||'').startsWith('['+i.tag+']')||(attrs.text||'').startsWith('['+i.tag+' ')),transform:attrs=>{const tag=attrs.text.match(/^\[([a-z_]+)/)[1], count=attrs.text.match(/\bcount=["']?(\d+)/),price=attrs.text.match(/\bprice=["']?([\d.]+)/);return wp.blocks.createBlock('auto-dealership/component',{component:tag,count:count?Number(count[1]):6,price:price?Number(price[1]):0});}}]}
    });
    const Sidebar = wp.editor?.PluginSidebar || wp.editPost?.PluginSidebar;
    if (Sidebar && wp.plugins) wp.plugins.registerPlugin('adc-component-library',{icon:'store',render:()=>el(Sidebar,{name:'adc-component-library',title:t('مكتبة مكونات الموقع','Website component library'),icon:'store'},el(Library))});
    wp.domReady(() => {
      if (!document.body.classList.contains('block-editor-page')) return;
      try {
        if (!localStorage.getItem('adc-component-library-seen')) {
          const store = wp.data.dispatch('core/interface');
          if (store?.enableComplementaryArea) { store.enableComplementaryArea('core/edit-post','adc-component-library/adc-component-library'); localStorage.setItem('adc-component-library-seen','1'); }
        }
      } catch (_) { /* Editor remains usable when browser storage is unavailable. */ }
    });
    if (wp.richText && wp.blockEditor.RichTextToolbarButton) wp.richText.registerFormatType('auto-dealership/brand-name',{title:t('إدراج اسم المنشأة','Insert business name'),tagName:'span',className:'adc-inline-brand',edit:props=>el(wp.blockEditor.RichTextToolbarButton,{icon:'admin-site-alt3',title:t('إدراج اسم المنشأة','Insert business name'),onClick:()=>props.onChange(wp.richText.insert(props.value,'[adc_brand_name]'))})});
  }
  function classic() {
    const root=document.getElementById('adc-classic-components'); if(!root) return;
    const list=root.querySelector('[data-adc-list]'),search=root.querySelector('[data-adc-search]'),feedback=root.querySelector('[data-adc-feedback]');
    function draw(){list.replaceChildren();items.filter(i=>(i.title+i.tag+i.description).toLowerCase().includes(search.value.toLowerCase())).forEach(item=>{const card=document.createElement('div');card.className='adc-library-item';const title=document.createElement('strong');title.textContent=item.title;const desc=document.createElement('p');desc.textContent=item.description;const btn=document.createElement('button');btn.type='button';btn.className='button';btn.textContent=t('إدراج المكوّن','Insert component');btn.addEventListener('click',()=>{const value=code(item);if(lastTranslation&&document.contains(lastTranslation)){insertTranslation(value);}else if(window.wp.media?.editor){wp.media.editor.insert(value);}else{const target=document.getElementById('content');if(target){target.setRangeText(value,target.selectionStart,target.selectionEnd,'end');target.dispatchEvent(new Event('input',{bubbles:true}));}}feedback.textContent=t('تم إدراج المكوّن.','Component inserted.');});card.append(title,desc,btn);list.append(card);});}
    search.addEventListener('input',draw);draw();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',classic);else classic();
})();
