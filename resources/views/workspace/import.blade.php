@extends('workspace.layout')
@section('title', 'Nhập bài .h5p')
@section('body')
<div class="row"><h1>Nhập bài .h5p</h1><a href="{{ route('workspace.index', $launch) }}">Quay lại kho bài</a></div>
<section class="hub-card">@include('workspace.import-form')</section>
@endsection
