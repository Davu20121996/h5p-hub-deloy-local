<p role="status"
   @if($submission?->delivered_at === null)
       data-h5p-submission="{{ $submission ? 'pending' : 'ready' }}"
       data-result-url="{{ route('content.result', [$launch, $content->uuid, 'attempt' => $attemptId]) }}"
       data-player-url="{{ route('content.play', [$launch, $content->uuid]) }}"
       data-csrf-token="{{ csrf_token() }}"
   @endif
>
    @if($submission?->delivered_at)
        Đã nộp bài. Client đã nhận kết quả.
    @elseif($submission)
        Đã nộp bài. Đang đồng bộ kết quả…
    @endif
</p>
<form method="post" action="{{ route('content.retry', [$launch, $content->uuid]) }}" data-h5p-retry @if($submission?->delivered_at === null) hidden @endif>
    @csrf
    <input type="hidden" name="attempt" value="{{ $attemptId }}">
    <button class="hub-button">Làm lại bài</button>
</form>
