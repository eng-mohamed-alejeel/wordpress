(function(api){
    'use strict';
    const data=window.adcBrandPreview;if(!data||!window.adcBrandColors)return;
    const engine=adcBrandColors.create(data.schema);
    api('adc_brand_palette',function(setting){function apply(value){let style=document.getElementById('adc-customizer-live-colors');if(!style){style=document.createElement('style');style.id='adc-customizer-live-colors';document.head.append(style);}style.textContent=engine.css(adcBrandColors.canonical(value));}apply(setting.get());setting.bind(apply);});
    api.bind('preview-ready',function(){api.preview.bind('adc-palette-mode',function(mode){if(!['light','dark'].includes(mode))return;document.documentElement.dataset.abTheme=mode;document.documentElement.dataset.theme=mode;});});
})(wp.customize);
