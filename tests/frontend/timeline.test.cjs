const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

test('compressed timeline preserves ordering, increases short runs, and survives refresh', () => {
    const bars = [
        {dataset: {start:'100', end:'101'}, style:{}},
        {dataset: {start:'1000', end:'1001'}, style:{}},
        {dataset: {start:'1002', end:'1002'}, style:{}},
    ];
    const buttons = ['true','compressed'].map(scale => ({dataset:{timelineScale:scale}, attributes:{}, setAttribute(k,v) {this.attributes[k]=v;}, addEventListener(k,fn) {this[k]=fn;}}));
    const description = {}, controls = {hidden:true};
    const panel = {
        dataset: {},
        querySelector(s) { return s === '[data-time-start]' ? {dataset:{timeStart:'0',timeEnd:'1002'}} : s === '.timeline-scale' ? controls : description; },
        querySelectorAll(s) { return s === '[data-start]' ? bars : buttons; },
    };
    const events = {};
    const document = {
        querySelectorAll(s) {return s === '[data-attempt-timeline]' ? [panel] : [];},
        querySelector() {return null;},
        addEventListener(k,fn) {events[k]=fn;},
    };
    vm.runInNewContext(fs.readFileSync('resources/js/steps.js','utf8'), {document});
    events.DOMContentLoaded();
    assert.equal(panel.dataset.timelineScale, 'compressed');
    assert.equal(buttons[1].attributes['aria-pressed'], 'true');
    buttons[0].click();
    const proportional = parseFloat(bars[0].style.width);
    buttons[1].click();
    assert.ok(parseFloat(bars[0].style.width) > proportional * 5);
    assert.ok(parseFloat(bars[0].style.left) < 10, 'long initial wait is compressed to a small gap');
    assert.equal(panel.dataset.timelineScale, 'compressed');
    assert.ok(parseFloat(bars[1].style.left) > parseFloat(bars[0].style.left));
    assert.equal(bars[2].style.width, '0%');
    assert.match(description.textContent, /not proportional/);
    events['lens:refreshed']();
    assert.equal(buttons[1].attributes['aria-pressed'], 'true');
    buttons[0].click();
    assert.equal(parseFloat(bars[0].style.width), proportional);
    assert.equal(controls.hidden, false);
});
