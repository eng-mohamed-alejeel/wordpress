'use strict';
// Explicit local acceptance: uploads only the workbook named on the command line.
const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
(async()=>{
    assert.equal(process.argv[2],'--apply-file');const file=path.resolve(process.argv[3]);assert.equal(path.extname(file).toLowerCase(),'.xlsx');
    const session=JSON.parse(fs.readFileSync('.tmp/dashboard-browser-session.json','utf8'));
    const headers={Cookie:session.cookies.map(c=>c.name+'='+c.value).join('; ')};
    const page='http://localhost/wordpress/wp-admin/admin.php?page=adc-onyx';
    const before=await fetch(page,{headers});assert.equal(before.status,200);const html=await before.text();
    const form=html.split('<form').find(f=>f.includes('value="adc_onyx_upload"')).split('</form>')[0];
    assert(!form.includes('name="mode"'));const nonce=form.match(/name="_wpnonce"[^>]*value="([^"]+)"/);assert(nonce);
    const data=new FormData();data.append('action','adc_onyx_upload');data.append('_wpnonce',nonce[1]);data.append('workbook',new Blob([fs.readFileSync(file)],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),path.basename(file));
    const response=await fetch('http://localhost/wordpress/wp-admin/admin-post.php',{method:'POST',headers,body:data,signal:AbortSignal.timeout(120000)});const result=await response.text();
    assert.equal(response.status,200);const url=new URL(response.url);assert.equal(url.searchParams.get('page'),'adc-onyx');assert(url.searchParams.get('batch'));assert(!url.searchParams.get('error'));
    assert(result.includes('notice-success'));assert(!result.includes('value="adc_onyx_apply"'));
    const evidence={http_status:response.status,batch:Number(url.searchParams.get('batch')),automatic_completion:true,manual_approval:false,mode_selection:false};
    fs.writeFileSync('docs/artifacts/onyx-inventory/automatic-snapshot-http-upload.json',JSON.stringify(evidence,null,2));console.log(JSON.stringify(evidence));
})().catch(e=>{console.error(e.message);process.exitCode=1;});
