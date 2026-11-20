@extends('workspace.layout')
@section('title', 'Kết quả học tập')
@section('body')
<h1>Kết quả · {{ $content->title }}</h1><p><a href="{{ route('workspace.index', ['launch'=>$launch->id]) }}">Về kho bài</a></p>
<div class="hub-card"><p class="muted">Kết quả ghi tại Hub; chưa đồng bộ điểm về website. Mỗi dòng là một sự kiện kết quả từ nội dung H5P.</p>
<div class="hub-table"><table><thead><tr><th>Người học</th><th>Website / vị trí bài</th><th>Điểm</th><th>Hoàn thành</th><th>Thời gian</th></tr></thead><tbody>
@forelse($attempts as $attempt)<tr><td>{{ $attempt->launch->identity->name ?? $attempt->launch->identity->subject }}</td><td>{{ $attempt->launch->deployment->platform->name }}<br><small>{{ $attempt->launch->resource_link_id }}</small></td><td>{{ $attempt->score ?? '—' }} / {{ $attempt->max_score ?? '—' }}</td><td>{{ $attempt->completed ? 'Có' : 'Chưa' }}</td><td>{{ $attempt->created_at->format('d/m/Y H:i') }}</td></tr>@empty<tr><td colspan="5">Chưa có kết quả.</td></tr>@endforelse
</tbody></table></div>{{ $attempts->links() }}</div>
@endsection
