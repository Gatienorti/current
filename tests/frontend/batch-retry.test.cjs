const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// Exercise the browser's actual transport/state machine without a DOM dependency.
class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.listeners = {}; this.attributes = new Set(); this.hidden = false; this.disabled = false; this.textContent = ''; }
    append(...children) { for (const child of children) { child.parent = this; this.children.push(child); } }
    remove() { this.parent.children = this.parent.children.filter(child => child !== this); }
    setAttribute(name) { this.attributes.add(name); }
    removeAttribute(name) { this.attributes.delete(name); }
    hasAttribute(name) { return this.attributes.has(name); }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    querySelector(selector) {
        if (selector === '[type="submit"]') return this.children.find(child => child.type === 'submit');
        const name = selector.match(/^\[name="(.+)"\]$/)?.[1];
        return this.children.find(child => child.name === name);
    }
    closest() { return this.panel; }
    fire(name) { return this.listeners[name]?.({preventDefault() {}}); }
}
class FormData {
    constructor(form) { this.values = new Map(form.children.filter(child => child.name).map(child => [child.name, child.value])); }
    get(name) { return this.values.get(name) ?? null; }
}
function setup(fetcher) {
    const form = new Element('form'), panel = new Element('section'), body = new Element('body');
    form.panel = panel; form.action = 'http://localhost/current/batches/batch/retry';
    for (const [name, value] of Object.entries({_token:'csrf', scope:'batch', retry_pass:'encrypted-pass'})) {
        const input = new Element('input'); Object.assign(input, {name,value}); form.append(input);
    }
    const submit = new Element('button'); submit.type = 'submit'; form.append(submit);
    const listeners = {};
    const document = {body, createElement: tag => new Element(tag), querySelectorAll: () => [form], addEventListener: (name, fn) => listeners[name] = fn};
    const requests = [];
    const context = {document, window:{location:new URL('http://localhost/current/batches/batch')}, FormData, URL, AbortController,
        setTimeout:(fn, delay) => setTimeout(fn, delay === 100 ? 0 : delay), clearTimeout,
        fetch: async (url, options) => { requests.push({url, data:options.body.values}); return fetcher(requests.length, form, panel); }};
    vm.runInNewContext(fs.readFileSync(require.resolve('../../resources/js/batch-retry.js'), 'utf8'), context);
    listeners.DOMContentLoaded();
    return {form,panel,body,submit,requests, run:() => form.fire('submit'), pause:() => form.children.find(child => child.textContent === 'Pause').fire('click'),
        status:() => panel.children.find(child => child.attributes.has('role')), resume:() => panel.children.find(child => child.tag === 'a')};
}
const result = (inspected, complete, cursor = 'cursor-000050', unconfirmed = 0) => ({submitted:inspected-unconfirmed, inspected, unconfirmed, complete,
    next:complete ? null : {retry_pass:'encrypted-pass',retry_after:cursor},
    resume_url:`/current/batches/batch?retry_pass=encrypted-pass&retry_after=${cursor}`});
const response = value => ({ok:true, headers:{get:()=>'application/json'}, json:async()=>value});

test('one user action retries all chunks sequentially with CSRF and releases refresh on completion', async () => {
    const page = setup(n => response(n < 3 ? result(50,false,`cursor-${String(n*50).padStart(6, '0')}`) : result(3,true)));
    await page.run();
    assert.equal(page.requests.length,3);
    assert.equal(page.requests[0].data.get('_token'),'csrf');
    assert.equal(page.requests[1].data.get('retry_after'),'cursor-000050');
    assert.equal(page.requests[2].data.get('retry_after'),'cursor-000100');
    assert.match(page.status().textContent,/103 retries/);
    assert.equal(page.submit.disabled,true);
    assert.equal(page.body.hasAttribute('data-current-batch-active'),false);
    await page.run(); assert.equal(page.requests.length,3);
});

test('network ambiguity never resends and preserves the last safe cursor for explicit recovery', async () => {
    const page = setup(n => { if (n===2) throw new Error('connection lost'); return response(result(50,false)); });
    await page.run();
    assert.equal(page.requests.length,2); assert.equal(page.submit.disabled,true);
    assert.match(page.status().textContent,/outcome is unknown/);
    assert.equal(new URL(page.resume().href).searchParams.get('retry_after'),'cursor-000050');
    assert.equal(page.body.hasAttribute('data-current-batch-active'),true);
});

test('unconfirmed items stop the pass without skipping that page', async () => {
    const page = setup(n => response(n===1 ? result(50,false) : result(50,false,'cursor-000100',1)));
    await page.run();
    assert.equal(page.requests.length,2); assert.match(page.status().textContent,/1 unavailable or unconfirmed/);
    assert.equal(new URL(page.resume().href).searchParams.get('retry_after'),'cursor-000050');
});

test('pause finishes the in-flight chunk and explicit continue uses its confirmed cursor', async () => {
    let page;
    page = setup(n => { if (n===1) { page.pause(); return response(result(50,false)); } return response(result(2,true)); });
    await page.run(); assert.equal(page.requests.length,1); assert.equal(page.submit.disabled,false);
    assert.equal(page.submit.textContent,'Continue batch retry');
    await page.run(); assert.equal(page.requests.length,2);
    assert.equal(page.requests[1].data.get('retry_after'),'cursor-000050');
});

test('malformed counts, cursors, passes and URLs stop automatic work', async () => {
    for (const invalid of [result(50,false,'cursor-000050'), result(50,false,'cursor-000010'),
        {...result(50,false,'cursor-000100'),next:{retry_pass:'changed-pass',retry_after:'cursor-000100'}},
        {...result(50,false,'cursor-000100'),submitted:0}, {...result(50,false,'cursor-000100'),resume_url:undefined},
        {...result(50,false,'cursor-000100'),resume_url:'https://other.example/batch'}]) {
        const page = setup(n => response(n===1 ? result(50,false) : invalid));
        await page.run(); assert.equal(page.requests.length,2); assert.equal(page.submit.disabled,true);
        assert.match(page.status().textContent,/outcome is unknown/);
    }
});

test('a double click cannot overlap an in-flight batch request', async () => {
    let finish;
    const page = setup(() => new Promise(resolve => { finish=resolve; }));
    const first = page.run(); await page.run(); assert.equal(page.requests.length,1);
    finish(response(result(1,true))); await first; assert.equal(page.requests.length,1);
});
