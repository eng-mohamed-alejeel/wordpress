'use strict';
// Offline LSP audit. Uses installed Intelephense, never executes PHP or connects to a database.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { pathToFileURL, fileURLToPath } = require('node:url');
const root = path.resolve(__dirname, '..');
const settings = JSON.parse(fs.readFileSync(path.join(root, '.vscode/settings.json'), 'utf8'));
const all = process.argv.includes('--all');
const owned = file => /^(?:wp-content\/plugins\/auto-dealership-core\/(?!tests\/)|wp-content\/themes\/car-dealer\/|tools\/)/.test(file);
const normalize = uri => fileURLToPath(uri).replaceAll('\\', '/').toLowerCase();
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
function globRegex(glob) {
    let expression = '';
    for (let i = 0; i < glob.length; i++) {
        const char = glob[i];
        if (char === '*' && glob[i + 1] === '*') {
            i++;
            if (glob[i + 1] === '/') { i++; expression += '(?:.*/)?'; }
            else expression += '.*';
        } else if (char === '*') expression += '[^/]*';
        else if (char === '?') expression += '[^/]';
        else if (char === '{') expression += '(?:';
        else if (char === '}') expression += ')';
        else if (char === ',') expression += '|';
        else expression += char.replace(/[.+^$()|[\]\\]/g, '\\$&');
    }
    return new RegExp('^' + expression + '$');
}
const excludes = settings['intelephense.files.exclude'].map(globRegex);
const excluded = file => excludes.some(pattern => pattern.test(file));
const documents = [];
function scan(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const absolute = path.join(directory, entry.name);
        const relative = path.relative(root, absolute).replaceAll('\\', '/');
        if (entry.isDirectory()) {
            if (!['.git', '.tmp', 'node_modules'].includes(entry.name) && !excluded(relative + '/')) scan(absolute);
        } else if (entry.name.endsWith('.php') && !excluded(relative) && (all || owned(relative))) documents.push(absolute);
    }
}
function findServer() {
    if (process.env.ADC_INTELEPHENSE_SERVER) return path.resolve(process.env.ADC_INTELEPHENSE_SERVER);
    const extensions = path.join(os.homedir(), '.vscode/extensions');
    if (fs.existsSync(extensions)) {
        const versions = fs.readdirSync(extensions).filter(name => name.startsWith('bmewburn.vscode-intelephense-client-')).sort((a, b) => b.localeCompare(a, undefined, { numeric: true }));
        for (const version of versions) {
            const candidate = path.join(extensions, version, 'node_modules/intelephense/lib/intelephense.js');
            if (fs.existsSync(candidate)) return candidate;
        }
    }
    throw new Error('Install Intelephense or set ADC_INTELEPHENSE_SERVER to its lib/intelephense.js.');
}
(async () => {
    scan(root);
    if (!documents.length) throw new Error('No PHP files selected.');
    const serverPath = findServer();
    const serverVersion = JSON.parse(fs.readFileSync(path.resolve(serverPath, '../../package.json'), 'utf8')).version;
    const output = path.join(root, '.tmp/intelephense-check');
    fs.mkdirSync(output, { recursive: true });
    const cache = fs.mkdtempSync(path.join(output, 'cache-'));
    const server = spawn(process.execPath, [serverPath, '--stdio'], { windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
    let sequence = 0, buffer = Buffer.alloc(0), activeUri, activeResolve, indexed = false;
    const pending = new Map(), collected = new Map();
    const logs = [];
    const config = { intelephense: {} };
    for (const [key, value] of Object.entries(settings)) {
        if (!key.startsWith('intelephense.')) continue;
        let target = config;
        const parts = key.split('.');
        for (const part of parts.slice(0, -1)) target = target[part] ??= {};
        target[parts.at(-1)] = value;
    }
    function send(message) {
        const body = Buffer.from(JSON.stringify(message));
        server.stdin.write('Content-Length: ' + body.length + '\r\n\r\n');
        server.stdin.write(body);
    }
    const notify = (method, params) => send({ jsonrpc: '2.0', method, params });
    const request = (method, params) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error('LSP timeout: ' + method)); }, 30000);
        pending.set(id, { resolve, reject, timer });
        send({ jsonrpc: '2.0', id, method, params });
    });
    function receive(message) {
        if (message.method) {
            if (message.method === 'textDocument/publishDiagnostics' && activeResolve && normalize(message.params.uri) === normalize(activeUri)) {
                collected.set(activeUri, message.params.diagnostics);
                activeResolve();
                activeResolve = null;
            }
            if (message.method === 'window/logMessage') {
                logs.push(message.params);
                if (message.params.message.includes('Indexing finished.')) indexed = true;
            }
            if (message.id !== undefined) {
                let result = null;
                if (message.method === 'workspace/configuration') result = message.params.items.map(item => (item.section || '').split('.').reduce((value, key) => value?.[key], config) ?? null);
                if (message.method === 'workspace/workspaceFolders') result = [{ uri: pathToFileURL(root + path.sep).href, name: 'wordpress' }];
                send({ jsonrpc: '2.0', id: message.id, result });
            }
        } else if (pending.has(message.id)) {
            const call = pending.get(message.id);
            pending.delete(message.id);
            clearTimeout(call.timer);
            message.error ? call.reject(new Error(JSON.stringify(message.error))) : call.resolve(message.result);
        }
    }
    server.stdout.on('data', data => {
        buffer = Buffer.concat([buffer, data]);
        while (true) {
            const boundary = buffer.indexOf('\r\n\r\n');
            if (boundary < 0) return;
            const length = Number(buffer.subarray(0, boundary).toString().match(/Content-Length: (\d+)/i)?.[1]);
            if (!Number.isFinite(length)) throw new Error('Invalid LSP frame.');
            if (buffer.length < boundary + 4 + length) return;
            const body = buffer.subarray(boundary + 4, boundary + 4 + length);
            buffer = buffer.subarray(boundary + 4 + length);
            receive(JSON.parse(body));
        }
    });
    server.stderr.on('data', data => logs.push({ stderr: data.toString() }));
    try {
        await request('initialize', {
            processId: process.pid, rootUri: pathToFileURL(root + path.sep).href,
            workspaceFolders: [{ uri: pathToFileURL(root + path.sep).href, name: 'wordpress' }],
            capabilities: { workspace: { configuration: true, workspaceFolders: true }, window: { workDoneProgress: true } },
            initializationOptions: { storagePath: cache, globalStoragePath: path.join(cache, 'global'), clearCache: true, isVscode: false }
        });
        notify('initialized', {});
        notify('workspace/didChangeConfiguration', { settings: config });
        for (let i = 0; i < 300 && !indexed; i++) await pause(200);
        if (!indexed) throw new Error('Workspace indexing did not complete.');
        console.log('Intelephense ' + serverVersion + ': checking ' + documents.length + ' files.');
        for (let i = 0; i < documents.length; i++) {
            activeUri = pathToFileURL(documents[i]).href;
            const done = new Promise(resolve => { activeResolve = resolve; });
            notify('textDocument/didOpen', { textDocument: { uri: activeUri, languageId: 'php', version: 1, text: fs.readFileSync(documents[i], 'utf8') } });
            await Promise.race([done, pause(15000)]);
            if (!collected.has(activeUri)) throw new Error('No diagnostics for ' + documents[i]);
            notify('textDocument/didClose', { textDocument: { uri: activeUri } });
            if ((i + 1) % 100 === 0) console.log('Checked ' + (i + 1) + '/' + documents.length);
        }
        const rows = [];
        for (const [uri, items] of collected) {
            for (const item of items) rows.push({ file: path.relative(root, fileURLToPath(uri)).replaceAll('\\', '/'), line: item.range.start.line + 1, column: item.range.start.character + 1, code: item.code, severity: item.severity, message: item.message });
        }
        const failures = rows.filter(row => owned(row.file) && row.severity === 1);
        const report = { serverVersion, phpTarget: settings['intelephense.environment.phpVersion'], scope: all ? 'all indexed PHP' : 'owned production and tools', documents: documents.length, published: collected.size, ownedErrors: failures.length, rows };
        fs.writeFileSync(path.join(output, 'diagnostics.json'), JSON.stringify(report, null, 2));
        for (const failure of failures) console.error(`${failure.file}:${failure.line} ${failure.code}: ${failure.message}`);
        console.log(`${rows.length} diagnostics; ${failures.length} owned errors. Report: .tmp/intelephense-check/diagnostics.json`);
        if (failures.length) process.exitCode = 1;
        await request('shutdown', null);
        notify('exit', null);
    } finally {
        fs.writeFileSync(path.join(output, 'server-log.json'), JSON.stringify(logs, null, 2));
        server.kill();
        // The disposable cache stays under .tmp; no user/editor cache is touched.
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
