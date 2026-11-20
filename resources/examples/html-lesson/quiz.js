var Hub = Hub || {};

Hub.HtmlLesson = function (params, contentId) {
    H5P.EventDispatcher.call(this);
    this.params = params;
    this.contentId = contentId;
};

Hub.HtmlLesson.prototype = Object.create(H5P.EventDispatcher.prototype);
Hub.HtmlLesson.prototype.constructor = Hub.HtmlLesson;

Hub.HtmlLesson.prototype.attach = function ($container) {
    const $ = H5P.jQuery;
    const questions = this.params.questions;
    const choices = ['a', 'b', 'c', 'd'];
    $container.addClass('hub-html-lesson-container').attr('tabindex', '-1');
    this.on('enterFullScreen', () => $container[0].focus({preventScroll: true}));
    const $lesson = $('<div class="hub-html-lesson">').appendTo($container);
    const $media = $('<section class="hub-lesson-media">').appendTo($lesson);
    $('<h2>').text('Video bài học').appendTo($media);

    const integration = H5PIntegration.contents['cid-' + this.contentId];
    const $video = $('<video controls playsinline preload="metadata">')
        .attr('aria-label', 'Video hướng dẫn HTML').appendTo($media);
    this.params.lessonVideo.forEach(function (file) {
        const path = /^https?:\/\//i.test(file.path)
            ? file.path
            : integration.contentUrl + '/' + file.path.split('/').map(encodeURIComponent).join('/');
        $('<source>').attr({src: path, type: file.mime}).appendTo($video);
    });
    $video.append(document.createTextNode('Trình duyệt của bạn không hỗ trợ phát video.'));
    $('<p class="hub-lesson-note">').text('Video minh họa bằng chữ, không có lời thoại.').appendTo($media);

    const $quiz = $('<section class="hub-lesson-quiz">').appendTo($lesson);
    const $intro = $('<div class="hub-quiz-intro">').appendTo($quiz);
    $('<h2>').text('Trắc nghiệm sau bài học').appendTo($intro);
    $('<p class="hub-lesson-note">').text('Chọn một đáp án cho mỗi câu rồi nộp bài để xem kết quả.').appendTo($intro);
    const $progress = $('<progress>').attr({'aria-label': 'Tiến độ trả lời', max: questions.length, value: 0}).appendTo($intro);
    const $progressText = $('<p class="hub-quiz-progress" role="status">').appendTo($intro);
    const $form = $('<form class="hub-quiz-form">').appendTo($quiz);

    const groups = questions.map(function (question, index) {
        const $group = $('<fieldset class="hub-quiz-question">').appendTo($form);
        const $legend = $('<legend>').appendTo($group);
        $('<span class="hub-quiz-number">').text('Câu ' + (index + 1)).appendTo($legend);
        $('<span>').text(question.prompt).appendTo($legend);
        const $options = $('<div class="hub-quiz-options">').appendTo($group);
        const options = choices.map(function (key) {
            const $option = $('<label class="hub-quiz-option">').appendTo($options);
            $('<input type="radio" required>').attr('name', 'answer-' + index).val(key).appendTo($option);
            $('<span class="hub-quiz-letter" aria-hidden="true">').text(key.toUpperCase()).appendTo($option);
            $('<span>').text(question[key]).appendTo($option);
            return $option;
        });

        return {$group, options};
    });

    const $actions = $('<div class="hub-quiz-actions">').appendTo($form);
    const $submit = $('<button type="submit">').text('Nộp bài').appendTo($actions);
    const $summary = $('<div class="hub-quiz-summary" role="status" tabindex="-1" hidden>').appendTo($form);
    const updateProgress = function () {
        const answered = $form.find('input:checked').length;
        $progress.val(answered);
        $progressText.text('Đã trả lời ' + answered + '/' + questions.length + ' câu');
    };
    updateProgress();
    $form.on('change', 'input[type="radio"]', updateProgress);

    $form.on('submit', (event) => {
        event.preventDefault();
        if ($submit.prop('disabled')) return;
        const answers = groups.map(({ $group }) => $group.find('input:checked').val());
        if (answers.some(answer => !answer)) return;
        const score = answers.filter((answer, index) => answer === questions[index].correct).length;

        $submit.prop('disabled', true).text('Đã nộp bài');
        groups.forEach(function ({ $group, options }, index) {
            const question = questions[index];
            const correct = answers[index] === question.correct;
            $group.prop('disabled', true);
            options[choices.indexOf(question.correct)]?.addClass('hub-quiz-option--correct');
            if (!correct) {
                options[choices.indexOf(answers[index])].addClass('hub-quiz-option--incorrect');
            }
            $('<p class="hub-quiz-feedback">')
                .toggleClass('hub-quiz-feedback--correct', correct)
                .text(correct ? 'Chính xác' : 'Đáp án đúng: ' + question[question.correct]).appendTo($group);
        });
        $('<h3>').text('Kết quả').appendTo($summary);
        $('<p>').text(score + '/' + questions.length + ' câu đúng').appendTo($summary);
        $summary.prop('hidden', false);
        $summary[0].focus();
        const completed = this.createXAPIEventTemplate('completed');
        completed.setScoredResult(score, questions.length, this, true, score === questions.length);
        completed.data.statement.result.response = answers.join(',');
        this.trigger(completed);
        this.trigger('resize');
    });
    $video.on('loadedmetadata', () => this.trigger('resize'));
    this.trigger('resize');
};
