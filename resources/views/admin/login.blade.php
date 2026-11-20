@extends('admin.layout')
@section('content')
<section class="hub-card hub-login"><h1>Đăng nhập quản trị</h1><p class="muted">Quản lý kết nối LMS và thư viện H5P.</p>
<form class="hub-form" method="post" action="{{ route('admin.authenticate') }}">@csrf
<div class="hub-field"><label for="username">Tên đăng nhập</label><input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus maxlength="100"></div>
<div class="hub-field"><label for="password">Mật khẩu</label><input id="password" type="password" name="password" autocomplete="current-password" required maxlength="255"></div>
<button class="hub-button">Đăng nhập</button></form><p><a href="{{ route('account.login') }}">Đăng nhập tenant-admin / giáo viên</a></p></section>
@endsection
