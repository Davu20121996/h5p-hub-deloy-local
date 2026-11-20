var Hub = Hub || {};
Hub.DemoQuestion = function (params, contentId) {
    H5P.EventDispatcher.call(this);
    this.params = params;
    this.contentId = contentId;
};
Hub.DemoQuestion.prototype = Object.create(H5P.EventDispatcher.prototype);
Hub.DemoQuestion.prototype.constructor = Hub.DemoQuestion;
Hub.DemoQuestion.prototype.attach = function ($container) {
    const self = this;
    $container.addClass('hub-demo-question');
    H5P.jQuery('<h2>').text(this.params.question).appendTo($container);
    const $result = H5P.jQuery('<p role="status">');
    [true, false].forEach(answer => {
        H5P.jQuery('<button type="button">').text(answer ? 'Có' : 'Không').appendTo($container).on('click', function () {
            const correct = answer === self.params.answer;
            $result.text(correct ? 'Chính xác!' : 'Chưa chính xác.');
            $container.find('button').prop('disabled', true);
            const event = self.createXAPIEventTemplate('completed');
            event.setScoredResult(correct ? 1 : 0, 1, self, true, correct);
            self.trigger(event);
            self.trigger('resize');
        });
    });
    $result.appendTo($container);
    this.trigger('resize');
};
