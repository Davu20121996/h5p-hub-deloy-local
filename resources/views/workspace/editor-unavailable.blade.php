@extends('workspace.layout')
@section('title', 'Tạo bài H5P')
@section('body')
<h1>Tạo bài H5P</h1>
<div class="hub-card">
    <p>HUB chưa có loại bài H5P để soạn. Hãy nhờ Admin hệ thống cài thư viện H5P.</p>
    <a href="{{ route('workspace.index', $launch) }}">Quay lại kho bài</a>
</div>
@endsection
