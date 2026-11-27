const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../../public/js/h5p-submission.js'), 'utf8');

test('the first completed event locks the player and retries exactly the same result', async () => {
    const status = {dataset: {h5pSubmission: 'ready', playerUrl: '/play', resultUrl: '/result'}};
    const player = {inert: false};
    const requests = [];
    const retries = [];
    let onXapi;

    vm.runInNewContext(script, {
        document: {querySelector: selector => selector === '.h5p-content' ? player : status},
        H5P: {externalDispatcher: {on: (event, listener) => { onXapi = listener; }}},
        fetch: async (url, options) => {
            requests.push(options.body);
            return {status: requests.length === 1 ? 502 : 204};
        },
        setTimeout: callback => retries.push(callback),
    });

    function completedEvent(score) {
        return {
            getVerb: () => 'completed',
            getVerifiedStatementValue: ([section]) => section === 'object' ? '/play' : true,
            getScore: () => score,
            getMaxScore: () => 10,
        };
    }

    onXapi(completedEvent(4));
    onXapi(completedEvent(10));
    await new Promise(setImmediate);
    assert.equal(player.inert, true);
    assert.equal(retries.length, 1);
    retries[0]();
    await new Promise(setImmediate);
    assert.equal(requests.length, 2);
    assert.equal(requests[1], requests[0]);
    assert.equal(JSON.parse(requests[1]).score, 4);
    assert.match(status.textContent, /Client đã nhận kết quả/);
});
