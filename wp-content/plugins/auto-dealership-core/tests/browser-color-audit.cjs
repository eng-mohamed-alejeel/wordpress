// Evaluated in the page. Image/gradient backgrounds need visual review and are skipped.
module.exports = function auditTextContrast() {
    const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
    const context = canvas.getContext('2d', {willReadFrequently:true});
    const cache = new Map();
    const rgba = color => {
        if(cache.has(color)) return cache.get(color);
        context.clearRect(0,0,1,1); context.fillStyle=color; context.fillRect(0,0,1,1);
        const data=[...context.getImageData(0,0,1,1).data]; data[3]/=255; cache.set(color,data); return data;
    };
    const over = (fg,bg) => fg.slice(0,3).map((v,i)=>v*fg[3]+bg[i]*(1-fg[3])).concat(1);
    const lum = color => color.slice(0,3).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
    const failures=[]; let tested=0, skipped=0;
    for(const element of document.querySelectorAll('body *')) {
        if(![...element.childNodes].some(n=>n.nodeType===3&&n.textContent.trim())) continue;
        if(element.closest('script,style,option,svg,.screen-reader-text,[aria-hidden="true"]'))continue;
        const box=element.getBoundingClientRect(), style=getComputedStyle(element);
        if(!box.width||!box.height||style.visibility!=='visible'||element.closest('[hidden]'))continue;
        const layers=[]; let image=false, opacity=1;
        for(let parent=element;parent;parent=parent.parentElement) {
            const s=getComputedStyle(parent); opacity*=Number(s.opacity);
            if(s.backgroundImage!=='none') {image=true;break;}
            const c=rgba(s.backgroundColor); layers.push(c); if(c[3]===1)break;
        }
        if(image||opacity<.99){skipped++;continue;}
        let bg=[255,255,255,1]; for(const c of layers.reverse())bg=over(c,bg);
        const fg=over(rgba(style.color),bg), a=lum(fg),b=lum(bg),ratio=(Math.max(a,b)+.05)/(Math.min(a,b)+.05);
        const size=parseFloat(style.fontSize),bold=Number(style.fontWeight)>=700;
        const min=size>=24||(bold&&size>=18.66)?3:4.5; tested++;
        if(ratio<min)failures.push({selector:element.tagName.toLowerCase()+(element.id?'#'+element.id:'')+(element.className?'.'+String(element.className).trim().replace(/\s+/g,'.'):''),text:element.textContent.trim().slice(0,65),ratio:Math.round(ratio*100)/100,min,color:style.color,bg:bg.slice(0,3)});
    }
    const tokens = getComputedStyle(document.documentElement);
    for(const [foreground,background,min] of [['text','bg',4.5],['text','surface',4.5],['heading','surface',4.5],['muted','surface',4.5],['link','surface',4.5],['on-action','action',4.5],['on-action','action-hover',4.5],['inverse','primary',4.5],['success','success-bg',4.5],['error','error-bg',4.5],['warning','warning-bg',4.5],['control-border','surface',3],['focus','surface',3]]) {
        const a=lum(rgba(tokens.getPropertyValue('--ab-'+foreground))),b=lum(rgba(tokens.getPropertyValue('--ab-'+background)));
        const ratio=(Math.max(a,b)+.05)/(Math.min(a,b)+.05); tested++;
        if(ratio<min)failures.push({selector:'token:'+foreground+'/'+background,ratio,min});
    }
    return {tested,skipped,failures};
};
