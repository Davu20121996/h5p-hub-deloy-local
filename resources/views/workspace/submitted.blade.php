@extends('workspace.layout')
@section('title', $content->title)
@section('body')
<h1>{{ $content->title }}</h1>
<div class="hub-card">@include('workspace.submission-status')</div>
@endsection
@section('scripts')
@if($submission->delivered_at === null)
    <script defer src="{{ asset('js/h5p-submission.js') }}?v={{ filemtime(public_path('js/h5p-submission.js')) }}"></script>
@endif
@endsection
