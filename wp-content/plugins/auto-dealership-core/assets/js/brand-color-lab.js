/* Perceptual color math and provenance; no published palette is migrated. */
(function(){'use strict';
    const rgb=hex=>[1,3,5].map(i=>parseInt(hex.slice(i,i+2),16)/255);
    const linear=v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4;
    const encoded=v=>v<=.0031308?12.92*v:1.055*v**(1/2.4)-.055;
    function lab(hex){const [r,g,b]=rgb(hex).map(linear),l=Math.cbrt(.4122214708*r+.5363325363*g+.0514459929*b),m=Math.cbrt(.2119034982*r+.6806995451*g+.1073969566*b),s=Math.cbrt(.0883024619*r+.2817188376*g+.6299787005*b);return[.2104542553*l+.793617785*m-.0040720468*s,1.9779984951*l-2.428592205*m+.4505937099*s,.0259040371*l+.7827717662*m-.808675766*s];}
    function inverse([L,a,b]){const l=(L+.3963377774*a+.2158037573*b)**3,m=(L-.1055613458*a-.0638541728*b)**3,s=(L-.0894841775*a-1.291485548*b)**3;return[4.0767416621*l-3.3077115913*m+.2309699292*s,-1.2684380046*l+2.6097574011*m-.3413193965*s,-.0041960863*l-.7034186147*m+1.707614701*s];}
    function color(value){let channels=inverse(value);if(channels.some(c=>c<-.000001||c>1.000001)){let low=0,high=1;for(let i=0;i<22;i++){const k=(low+high)/2;if(inverse([value[0],value[1]*k,value[2]*k]).every(c=>c>=0&&c<=1))low=k;else high=k;}channels=inverse([value[0],value[1]*low,value[2]*low]);}return'#'+channels.map(v=>Math.round(Math.max(0,Math.min(1,encoded(Math.max(0,v))))*255).toString(16).padStart(2,'0')).join('').toUpperCase();}
    function mix(a,b,amount){if(amount<=0)return a.toUpperCase();if(amount>=1)return b.toUpperCase();const x=lab(a),y=lab(b);return color(x.map((v,i)=>v*(1-amount)+y[i]*amount));}
    function harmony(hex,degrees,L=null,chroma=1){const value=lab(hex),C=Math.hypot(value[1],value[2])*chroma,h=Math.atan2(value[2],value[1])+degrees*Math.PI/180;return color([L===null?value[0]:Math.max(0,Math.min(1,L)),C*Math.cos(h),C*Math.sin(h)]);}
    function prepare(base,meta,preserve){const next=preserve?window.adcBrandColors.canonical(base):{format:1,light:{},dark:{}};if(preserve)for(const mode of ['light','dark'])for(const [key,value] of Object.entries(meta?.generated?.[mode]||{}))if(next[mode][key]===value)delete next[mode][key];return next;}
    function origin(config,meta,mode,key){const value=config[mode]?.[key];if(!value)return'automatic';if(meta?.generated?.[mode]?.[key]!==value)return'manual';return(meta.extracted||[]).includes(value)?'extracted':'generated';}
    function metadata(config,base,old,extracted,roles,preserve,style=''){const manual=prepare(base,old,preserve),generated={light:{},dark:{}};for(const mode of ['light','dark'])for(const [key,value] of Object.entries(config[mode]))if(!Object.hasOwn(manual[mode],key)||['brand-blue','brand-red','brand-navy'].includes(key))generated[mode][key]=value;return{format:1,generated,extracted:[...extracted],roles:{...roles},style};}
    window.adcColorLab={lab,color,mix,harmony,prepare,origin,metadata};
})();
