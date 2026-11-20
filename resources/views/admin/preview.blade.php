@extends('admin.layout')
@section('content')
<p><a href="{{ route('admin.tenants.show', [$tenant, 'tab'=>'contents']) }}">← Bài H5P của {{ $tenant->name }}</a></p>
<h1>{{ $content->title }}</h1><p class="muted">Xem trước quản trị · Không ghi kết quả học tập</p>
@foreach($styles as $style)<link rel="stylesheet" href="{{ $style }}">@endforeach
<section class="hub-card"><div class="h5p-content" data-content-id="{{ $content->id }}"></div></section>
@endsection
@section('scripts')
<script>window.H5PIntegration = {{ Illuminate\Support\Js::from($integration) }};</script>
@foreach($scripts as $script)<script src="{{ $script }}"></script>@endforeach
@endsection
