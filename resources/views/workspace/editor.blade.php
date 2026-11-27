@extends('workspace.layout')
@section('title', 'Soạn bài H5P')
@section('head')@foreach($styles as $style)<link rel="stylesheet" href="{{ $style }}">@endforeach @endsection
@section('body')
<div class="row">
    <h1>{{ $content ? 'Sửa bài H5P' : 'Tạo bài H5P' }}</h1>
    @if($content)
        <a href="{{ route('content.play', [$launch, $content->uuid]) }}">Xem trước bài đã lưu</a>
    @endif
</div>
<form id="editor-form" method="post" action="{{ $content ? route('content.update', ['launch'=>$launch->id,'uuid'=>$content->uuid]) : route('content.store', ['launch'=>$launch->id]) }}">
@csrf<input type="hidden" name="title" id="content-title"><input type="hidden" name="library" id="content-library"><input type="hidden" name="parameters" id="content-parameters">
<div class="hub-card"><div id="h5p-editor">Đang tải trình soạn bài…</div></div>
<div class="hub-card hub-form hub-form--grid">
    <div class="hub-field">
        <label for="editor-status">Trạng thái</label>
        <select id="editor-status" name="status">
            <option value="draft" @selected(old('status', $content?->status ?? 'draft') === 'draft')>Bản nháp</option>
            <option value="published" @selected(old('status', $content?->status) === 'published')>Xuất bản</option>
        </select>
    </div>
    <div class="hub-actions">
        <button class="hub-button" type="submit">Lưu bài</button>
        <span id="save-error" class="muted" role="status"></span>
    </div>
</div>
</form>
@if($content?->status === 'published' && $launch->message_type === 'LtiDeepLinkingRequest' && !$launch->completed_at)
    <form class="hub-form" method="post" action="{{ route('lti.complete', $launch) }}">
        @csrf
        <input type="hidden" name="content_id" value="{{ $content->uuid }}">
        <button class="hub-button">Gắn bài đã lưu vào khóa học</button>
    </form>
@endif
@endsection
@section('scripts')
<script>window.H5PIntegration = {{ Illuminate\Support\Js::from($integration) }}; window.H5PEditor = {language:{}};</script>
@foreach($integration['core']['scripts'] ?? array_slice($scripts, 0, count(H5PCore::$scripts)) as $script)<script src="{{ $script }}"></script>@endforeach
<script src="{{ route('h5p.assets', ['kind'=>'editor','path'=>'scripts/h5peditor-editor.js']) }}"></script>
<script src="{{ route('h5p.assets', ['kind'=>'editor','path'=>'language/en.js']) }}"></script>
<script>
const settings = H5PIntegration.editor;
Object.assign(H5PEditor, {$:H5P.jQuery,basePath:settings.libraryUrl,filesPath:settings.filesPath,ajaxPath:settings.ajaxPath,fileIcon:settings.fileIcon,
apiVersion:settings.apiVersion,contentId:settings.nodeVersionId,contentLanguage:settings.language,copyrightSemantics:settings.copyrightSemantics,
metadataSemantics:settings.metadataSemantics,assets:settings.assets,baseUrl:'',enableContentHub:false});
const pendingFields = new Set();
const editor = new H5PEditor.Editor({{ Illuminate\Support\Js::from($libraryString) }}, {{ Illuminate\Support\Js::from($parameters) }}, document.getElementById('h5p-editor'), function () {
    pendingFields.clear();
    this.document.addEventListener('input', event => {
        if (event.target.matches('input[type="text"], input[type="number"], textarea')) {
            pendingFields.add(event.target);
        }
    });
    this.document.addEventListener('change', event => pendingFields.delete(event.target));
});
const saveError = document.getElementById('save-error');
document.getElementById('editor-form').addEventListener('submit', function(event) {
    event.preventDefault();
    saveError.textContent = '';
    if (!editor.selector?.form) {
        saveError.textContent = 'Chọn loại bài và đợi trình soạn thảo tải xong.';
        return;
    }
    for (const field of pendingFields) {
        if (field.isConnected) {
            field.dispatchEvent(new Event('change', {bubbles: true}));
        }
        pendingFields.delete(field);
    }
    editor.getContent(content => {
        document.getElementById('content-title').value=content.title;
        document.getElementById('content-library').value=content.library;
        document.getElementById('content-parameters').value=typeof content.params === 'string' ? content.params : JSON.stringify(content.params);
        this.submit();
    }, error => {
        saveError.textContent = error === 'missing-title'
            ? 'Nhập tiêu đề bài trước khi lưu.'
            : 'Chưa thể lưu bài. Hãy kiểm tra các trường bắt buộc trong trình soạn thảo.';
    });
});
</script>
@endsection
