@extends('workspace.layout')
@section('title', $content->title)
@section('head')@foreach(array_merge($coreStyles, $styles) as $style)<link rel="stylesheet" href="{{ $style }}">@endforeach @endsection
@section('body')
<div class="row"><h1>{{ $content->title }}</h1>@if($launch->can_author)<span class="muted">Xem trước</span>@if($content->lti_identity_id === $launch->lti_identity_id)<a href="{{ route('workspace.index', ['launch'=>$launch->id]) }}">Quay lại kho bài</a>@endif @endif</div>
<div class="hub-card"><div class="h5p-content" data-content-id="{{ $content->id }}"></div></div>
@endsection
@section('scripts')
<script>window.H5PIntegration = {{ Illuminate\Support\Js::from($integration) }};</script>
@foreach(array_merge($coreScripts, $scripts) as $script)<script src="{{ $script }}"></script>@endforeach

@endsection
