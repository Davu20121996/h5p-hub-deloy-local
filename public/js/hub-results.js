(function (global) {
    'use strict';
    global.H5PHubResults = {
        attach: function (options) {
            global.H5P.externalDispatcher.on('xAPI', function (event) {
                const statement = event.data && event.data.statement;
                if (!statement) return;
                const verb = statement.verb && statement.verb.id && statement.verb.id.split('/').pop();
                if (!['answered', 'completed', 'passed', 'failed'].includes(verb)) return;
                const parent = statement.context && statement.context.contextActivities && statement.context.contextActivities.parent;
                const extensions = statement.object && statement.object.definition && statement.object.definition.extensions;
                if ((parent && parent.length) || (extensions && extensions['http://h5p.org/x-api/h5p-subContentId'])) return;
                const result = statement.result || {};
                const score = result.score || {};
                const payload = {
                    event_id: statement.id || global.crypto.randomUUID(),
                    score: score.raw ?? null, max_score: score.max ?? null,
                    completed: result.completion === true || verb === 'completed', result: result
                };
                const status = global.document.getElementById('result-status');
                global.fetch(options.endpoint, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': options.csrfToken},
                    body: JSON.stringify(payload)
                }).then(function (response) {
                    if (!response.ok) throw new Error('Result save failed');
                    if (status) status.textContent = 'Đã lưu kết quả trên H5P Hub.';
                }).catch(function () {
                    if (status) status.textContent = 'Chưa lưu được kết quả. Kiểm tra kết nối hoặc mở lại bài từ website.';
                });
            });
        }
    };
})(window);
