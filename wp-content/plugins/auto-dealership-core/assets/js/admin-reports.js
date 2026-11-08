(function(){
	'use strict';

	function escapeHtml(value){
		return String(value).replace(/[&<>"']/g,function(character){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];});
	}

	function buildReportDocument(report){
		var source=report.cloneNode(true);
		var heading=source.querySelector('h1');
		var title=heading ? heading.textContent.trim() : document.title;
		var direction=report.getAttribute('dir')||document.documentElement.getAttribute('dir')||'rtl';
		var language='ltr'===direction?'en':'ar';

		if(heading){
			var introduction=heading.nextElementSibling;
			heading.remove();
			if(introduction&&'P'===introduction.tagName&&!introduction.querySelector('input,select,button')){introduction.remove();}
		}

		source.querySelectorAll('form,.adc-report-actions,.adc-export-actions,.no-print,button,input,select,textarea,script,style,link,.notice').forEach(function(node){node.remove();});
		source.querySelectorAll('a').forEach(function(link){link.replaceWith(document.createTextNode(link.textContent));});
		source.querySelectorAll('section').forEach(function(section){
			var text=section.textContent.toLowerCase();
			if(!section.querySelector('table,.adc-report-chart,.adc-report-summary,.adc-crm-summary,.adc-affiliate-summary,.adc-comparison-grid,.adc-report-chart-grid')&&(/quick access|الوصول السريع/.test(text))){section.remove();}
		});
		source.className='report-sheet';
		source.removeAttribute('style');

		return {title:title,direction:direction,language:language,content:source.innerHTML};
	}

	function openReportTab(button){
		var report=button.closest('.wrap')||document.querySelector('.wrap');
		if(!report){window.alert('تعذر العثور على محتوى التقرير.');return;}
		var popup=window.open('','_blank');
		if(!popup){window.alert('يرجى السماح بالنوافذ المنبثقة لفتح التقرير في تبويب جديد.');return;}
		var documentData=buildReportDocument(report);
		var css='<style>'+
			'@page{size:A4 landscape;margin:12mm}'+
			':root{color-scheme:light}'+
			'html,body{margin:0;padding:0;background:#eef2f7;color:#172033;font-family:Arial,"DejaVu Sans",sans-serif;line-height:1.5}'+
			'body{padding:28px}.report-sheet{max-width:1180px;margin:0 auto;padding:34px 38px;background:#fff;border:1px solid #d8e0ea;border-radius:12px;box-shadow:0 8px 28px rgba(23,32,51,.08)}'+
			'.report-sheet:before{content:"REPORT";display:block;margin-bottom:8px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:.16em}'+
			'h2{margin:28px 0 12px;padding-bottom:8px;border-bottom:2px solid #dbe4ef;color:#17324d;font-size:18px}'+
			'h3{color:#24415f}'+
			'p.description,.report-sheet>p{color:#526174}'+
			'.card{break-inside:avoid;background:#fff;border:1px solid #d8e0ea;border-radius:8px;padding:14px;margin:12px 0;box-shadow:none}'+
			'.adc-report-summary,.adc-crm-summary,.adc-affiliate-summary,.adc-comparison-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0}'+
			'.adc-report-kpi strong,.adc-crm-summary strong,.adc-affiliate-summary strong{display:block;color:#17324d;font-size:20px}'+
			'.adc-report-kpi span,.adc-report-kpi small{display:block}'+
			'table{width:100%;border-collapse:collapse;margin:14px 0;table-layout:fixed;background:#fff}'+
			'th,td{padding:9px 10px;border:1px solid #d8e0ea;text-align:inherit;vertical-align:top;overflow-wrap:anywhere;word-break:break-word}'+
			'th{background:#eaf1f8;color:#17324d;font-weight:700}'+
			'tbody tr:nth-child(even){background:#f8fafc}'+
			'.adc-report-bars{display:grid;gap:12px;margin:14px 0}'+
			'.adc-report-bar-label{display:flex;justify-content:space-between;gap:16px}'+
			'.adc-report-bar-track{height:12px;overflow:hidden;background:#e2e8f0;border-radius:999px}'+
			'.adc-report-bar-track span{display:block;width:var(--adc-report-bar-width);height:100%;background:linear-gradient(90deg,#2271b1,#36a269);border-radius:999px}'+
			'.adc-report-chart-data{display:table!important}'+
			'.adc-report-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}'+
			'progress{max-width:100%}'+
			'@media(max-width:780px){body{padding:10px}.report-sheet{padding:22px 16px}.adc-report-chart-grid{grid-template-columns:1fr}}'+
			'@media print{body{padding:0;background:#fff}.report-sheet{max-width:none;border:0;border-radius:0;box-shadow:none;padding:0}.report-sheet:before{display:none}h2{break-after:avoid}.card{break-inside:avoid}table{break-inside:auto}thead{display:table-header-group}tr{break-inside:avoid}}'+
			'</style>';
		popup.document.open();
		popup.document.write('<!doctype html><html lang="'+escapeHtml(documentData.language)+'" dir="'+escapeHtml(documentData.direction)+'"><head><meta charset="utf-8"><title>'+escapeHtml(documentData.title)+'</title>'+css+'</head><body><main class="report-document"><header class="report-header"><h1>'+escapeHtml(documentData.title)+'</h1><p>Generated report</p></header><section class="report-sheet" dir="'+escapeHtml(documentData.direction)+'">'+documentData.content+'</section></main></body></html>');
		popup.document.close();
		popup.focus();
	}

	document.addEventListener('click',function(event){
		var button=event.target.closest('[data-adc-report-print]');
		if(!button){return;}
		event.preventDefault();
		openReportTab(button);
	});
})();
