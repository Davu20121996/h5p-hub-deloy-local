@extends('workspace.layout')
@section('title', 'Soạn bài H5P')
@section('head')@foreach($styles as $style)<link rel="stylesheet" href="{{ $style }}">@endforeach @endsection
@section('body')
<div class="row"><h1>{{ $content ? 'Sửa bài H5P' : 'Tạo bài H5P' }}</h1><a href="{{ route('workspace.index', ['launch'=>$launch->id]) }}">Quay lại</a></div>
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
@endsection
@section('scripts')
<script>window.H5PIntegration = {{ Illuminate\Support\Js::from($integration) }}; window.H5PEditor = {language:{}};</script>
@foreach($integration['core']['scripts'] ?? array_slice($scripts, 0, count(H5PCore::$scripts)) as $script)<script src="{{ $script }}"></script>@endforeach
<script src="{{ route('h5p.assets', ['kind'=>'editor','path'=>'scripts/h5peditor-editor.js']) }}"></script>
<script src="{{ route('h5p.assets', ['kind'=>'editor','path'=>'language/en.js']) }}"></script>
<script>
const settings = H5PIntegration.editor;
Object.assign(H5PEditor, {$:H5P.jQuery,basePath:settings.libraryUrl,filesPath:settings.filesPath,ajaxPath:settings.ajaxPath,
apiVersion:settings.apiVersion,contentId:settings.nodeVersionId,contentLanguage:settings.language,copyrightSemantics:settings.copyrightSemantics,
metadataSemantics:settings.metadataSemantics,assets:settings.assets,baseUrl:'',enableContentHub:false});
const editor = new H5PEditor.Editor({{ Illuminate\Support\Js::from($libraryString) }}, {{ Illuminate\Support\Js::from($parameters) }}, document.getElementById('h5p-editor'));
document.getElementById('editor-form').addEventListener('submit', function(event) {
    event.preventDefault();
    if (!editor.selector) { document.getElementById('save-error').textContent='Chọn loại bài trước khi lưu.'; return; }
    editor.getContent(content => {
        document.getElementById('content-title').value=content.title;
        document.getElementById('content-library').value=content.library;
        document.getElementById('content-parameters').value=typeof content.params === 'string' ? content.params : JSON.stringify(content.params);
        this.submit();
    }, error => { document.getElementById('save-error').textContent='Chưa thể lưu: '+error; });
});
</script>
@endsection
