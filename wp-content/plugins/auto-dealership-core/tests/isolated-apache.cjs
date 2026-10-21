'use strict';
// Optional Windows TS-PHP acceptance server. Never reads the installed Apache configuration.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {spawn,spawnSync}=require('node:child_process');
const [portText,configuration,phpBinary]=process.argv.slice(2),port=Number(portText);
if(process.platform!=='win32'||!Number.isInteger(port)||port<1024||port>65535||[3306,3307].includes(port))throw Error('Invalid isolated Apache invocation');
const directory=fs.realpathSync(path.dirname(configuration));
if(path.dirname(directory)!==fs.realpathSync(os.tmpdir())||!/^adc-product-[a-f0-9]+$/.test(path.basename(directory)))throw Error('Expected the disposable package directory');
const data=JSON.parse(fs.readFileSync(configuration,'utf8'));
if(!/^adc_verify_[a-f0-9]{16}$/.test(data.database)||[3306,3307].includes(Number(data.port))||fs.realpathSync(data.content)!==fs.realpathSync(path.join(directory,'content')))throw Error('Invalid isolated package metadata');
const apache=fs.realpathSync(process.env.ADC_PRODUCT_APACHE_ROOT),php=path.dirname(fs.realpathSync(phpBinary));
const modulePath=path.join(php,'php8apache2_4.dll');
if(!fs.existsSync(modulePath))throw Error('This server requires a thread-safe PHP Apache module');
const quote=value=>JSON.stringify(String(value).replaceAll('\\','/'));
const documentRoot=path.join(directory,'apache-root');fs.mkdirSync(documentRoot);
const router=path.join(__dirname,'product-package-router.php');
const lines=[`ServerRoot ${quote(apache)}`,`Listen 127.0.0.1:${port}`,`ServerName 127.0.0.1:${port}`,`PidFile ${quote(path.join(directory,'apache.pid'))}`,`ErrorLog ${quote(path.join(directory,'apache-error.log'))}`,'LogLevel warn','ThreadsPerChild 16','MaxConnectionsPerChild 0'];
for(const name of ['authz_core','authz_host','env','mime','alias','rewrite'])lines.push(`LoadModule ${name}_module ${quote(path.join(apache,'modules','mod_'+name+'.so'))}`);
lines.push(`LoadModule php_module ${quote(modulePath)}`,`PHPIniDir ${quote(php)}`,`TypesConfig ${quote(path.join(apache,'conf','mime.types'))}`,`DocumentRoot ${quote(documentRoot)}`);
lines.push('<Directory />\nAllowOverride None\nRequire all denied\n</Directory>',`<Directory ${quote(documentRoot)}>\nAllowOverride None\nRequire local\n</Directory>`,`<Directory ${quote(__dirname)}>\nAllowOverride None\nRequire local\n</Directory>`);
lines.push('<FilesMatch "\\.php$">\nSetHandler application/x-httpd-php\n</FilesMatch>',`SetEnv ADC_PRODUCT_TEST_CONFIG ${quote(configuration)}`,'SetEnv ADC_PRODUCT_TEST_APACHE 1',`Alias /__adc_package_router.php ${quote(router)}`,'RewriteEngine On','RewriteRule ^/(?!__adc_package_router\\.php$).* /__adc_package_router.php [PT,L]');
const file=path.join(directory,'apache.conf');fs.writeFileSync(file,lines.join('\n')+'\n');
const env={...process.env,PATH:php+';'+path.join(apache,'bin')+';'+process.env.PATH};
const executable=path.join(apache,'bin','httpd.exe');
const syntax=spawnSync(executable,['-f',file,'-t'],{env,windowsHide:true,encoding:'utf8'});
if(syntax.status!==0)throw Error('Isolated Apache configuration failed: '+syntax.stdout+syntax.stderr);
const child=spawn(executable,['-f',file],{env,windowsHide:true,stdio:['ignore','pipe','pipe']});
child.stdout.pipe(process.stdout);child.stderr.pipe(process.stderr);
let closing=false;
function close(){if(closing)return;closing=true;if(child.exitCode===null&&child.pid)spawnSync('taskkill',['/PID',String(child.pid),'/T','/F'],{windowsHide:true,stdio:'ignore'});}
// PHP retains stdin as a lifetime lease; closing it also cleans up the worker tree.
process.stdin.resume();process.stdin.on('end',close);process.on('exit',close);
child.on('error',error=>{console.error(error);process.exitCode=1;});
child.on('exit',code=>{if(!closing&&code!==0)process.exitCode=code||1;process.stdin.pause();});
