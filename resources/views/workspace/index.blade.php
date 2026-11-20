@extends('workspace.layout')
@section('title', 'Kho bài H5P')
@section('body')
<h1>Kho bài H5P</h1><p class="muted">Nhập bài từ tệp .h5p, xem trước và xuất bản.</p>
<section class="hub-card"><h2>Nhập bài .h5p</h2>@include('workspace.import-form')</section>
<div class="hub-card"><div class="hub-table"><table><thead><tr><th>Bài H5P</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
@forelse($contents as $content)<tr><td>{{ $content->title }}</td><td>{{ $content->status === 'published' ? 'Đã xuất bản' : 'Bản nháp' }}</td><td><div class="row">
<a href="{{ route('content.play', ['launch'=>$launch->id,'uuid'=>$content->uuid]) }}">Xem trước</a>
@if($content->lti_identity_id === $launch->lti_identity_id)<form method="post" action="{{ route('content.status', [$launch, $content->uuid]) }}">@csrf @method('patch')<input type="hidden" name="status" value="{{ $content->status === 'published' ? 'draft' : 'published' }}"><button class="hub-button hub-button--secondary hub-button--small">{{ $content->status === 'published' ? 'Chuyển về nháp' : 'Xuất bản' }}</button></form><a href="{{ route('content.export', ['launch'=>$launch->id,'uuid'=>$content->uuid]) }}">Xuất .h5p</a>@endif
@if($content->lti_identity_id === $launch->lti_identity_id)
<form method="post" action="{{ route('content.destroy', ['launch'=>$launch->id,'uuid'=>$content->uuid]) }}">@csrf @method('DELETE')<button class="hub-button hub-button--secondary hub-button--small" onclick="return confirm('Xóa bài chưa được sử dụng?')">Xóa</button></form>@endif
<form method="post" action="{{ route('content.duplicate', ['launch'=>$launch->id,'uuid'=>$content->uuid]) }}">@csrf<button class="hub-button hub-button--secondary hub-button--small">Nhân bản</button></form>
@if($launch->message_type === 'LtiDeepLinkingRequest' && $content->status === 'published' && !$launch->completed_at)<form method="post" action="{{ route('lti.complete', ['launch'=>$launch->id]) }}">@csrf<input type="hidden" name="content_id" value="{{ $content->uuid }}"><button class="hub-button">Chọn bài</button></form>@endif
</div></td></tr>@empty<tr><td colspan="3" class="muted">Chưa có bài. Chọn tệp .h5p bên trên để nhập bài.</td></tr>@endforelse
</tbody></table></div>{{ $contents->links() }}</div>
@endsection
