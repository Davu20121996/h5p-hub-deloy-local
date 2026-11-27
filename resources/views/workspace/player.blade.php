@extends('workspace.layout')
@section('title', $content->title)
@section('head')@foreach(array_merge($coreStyles, $styles) as $style)<link rel="stylesheet" href="{{ $style }}">@endforeach @endsection
@section('body')
<div class="row">
    <h1>{{ $content->title }}</h1>
    @if($launch->can_author)
        <span class="muted">Xem trước</span>
        @if($content->lti_identity_id === $launch->lti_identity_id)
            <a class="hub-button hub-button--secondary" href="{{ route('content.edit', [$launch, $content->uuid]) }}">Sửa bài</a>
        @endif
    @endif
</div>
<div class="hub-card"><div class="h5p-content" data-content-id="{{ $content->id }}"></div></div>
@endsection
@section('scripts')
<script>window.H5PIntegration = {{ Illuminate\Support\Js::from($integration) }};</script>
@foreach(array_merge($coreScripts, $scripts) as $script)<script src="{{ $script }}"></script>@endforeach
@if($launch->canReportResult($content->uuid))
<script>
    const playerUrl = {{ Illuminate\Support\Js::from($integration['contents']['cid-'.$content->id]['url']) }};
    const resultUrl = {{ Illuminate\Support\Js::from(route('content.result', [$launch, $content->uuid])) }};
    const csrfToken = {{ Illuminate\Support\Js::from(csrf_token()) }};
    const status = document.createElement('p');
    status.setAttribute('role', 'status');
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'hub-button';
    retry.hidden = true;
    retry.textContent = 'Gửi lại kết quả';
    document.querySelector('.h5p-content').after(status, retry);

    let lastResult;
    let sending = false;
    async function sendResult() {
        if (sending || !lastResult) {
            return;
        }

        sending = true;
        retry.hidden = true;
        status.textContent = 'Đang gửi kết quả về Client…';
        const result = lastResult;
        try {
            const response = await fetch(resultUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                body: JSON.stringify(result),
                keepalive: true
            });
            if (!response.ok) {
                throw new Error('Score delivery failed');
            }
            status.textContent = 'Đã gửi kết quả về Client.';
        } catch {
            status.textContent = 'Chưa gửi được kết quả về Client.';
            retry.hidden = false;
        } finally {
            sending = false;
            if (result !== lastResult) {
                void sendResult();
            }
        }
    }

    retry.addEventListener('click', sendResult);
    H5P.externalDispatcher.on('xAPI', event => {
        if (!['completed', 'answered'].includes(event.getVerb())
            || event.getVerifiedStatementValue(['object', 'id']) !== playerUrl
            || event.getVerifiedStatementValue(['result', 'completion']) !== true) {
            return;
        }

        const score = event.getScore();
        const maximum = event.getMaxScore();
        lastResult = {timestamp: new Date().toISOString()};
        if (Number.isFinite(score) && Number.isFinite(maximum) && maximum > 0 && score >= 0 && score <= maximum) {
            Object.assign(lastResult, {score, maximum});
        }
        void sendResult();
    });
</script>
@endif
@endsection
