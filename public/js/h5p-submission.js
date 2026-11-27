const submissionStatus = document.querySelector('[data-h5p-submission]');
const {h5pSubmission, resultUrl, playerUrl, csrfToken} = submissionStatus.dataset;
const retryDelays = [2000, 5000, 15000, 30000];

async function deliverSubmission(result, attempt = 0) {
    submissionStatus.textContent = 'Đang gửi bài đã nộp về Client…';
    try {
        const response = await fetch(resultUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify(result),
            keepalive: true,
        });
        if (response.status === 204) {
            submissionStatus.textContent = 'Đã nộp bài. Client đã nhận kết quả.';
            return;
        }
        if ([401, 403, 404, 419, 422].includes(response.status)) {
            submissionStatus.textContent = 'Chưa gửi được kết quả. Hãy mở lại bài từ Client.';
            return;
        }
    } catch {
        // Retry the same result if the connection dropped.
    }

    if (attempt < retryDelays.length) {
        submissionStatus.textContent = 'Chưa đồng bộ được. Hệ thống sẽ tự gửi lại cùng kết quả.';
        setTimeout(() => void deliverSubmission(result, attempt + 1), retryDelays[attempt]);
    } else {
        submissionStatus.textContent = 'Chưa gửi được kết quả. Hãy mở lại bài từ Client để tiếp tục đồng bộ.';
    }
}

if (h5pSubmission === 'pending') {
    void deliverSubmission({});
} else {
    let submitted = false;
    H5P.externalDispatcher.on('xAPI', event => {
        if (submitted || !['completed', 'answered'].includes(event.getVerb())
            || event.getVerifiedStatementValue(['object', 'id']) !== playerUrl
            || event.getVerifiedStatementValue(['result', 'completion']) !== true) {
            return;
        }

        submitted = true;
        document.querySelector('.h5p-content').inert = true;
        const result = {timestamp: new Date().toISOString()};
        const score = event.getScore();
        const maximum = event.getMaxScore();
        if (Number.isFinite(score) && Number.isFinite(maximum) && maximum > 0 && score >= 0 && score <= maximum) {
            Object.assign(result, {score, maximum});
        }
        void deliverSubmission(result);
    });
}
