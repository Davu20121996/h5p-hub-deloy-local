@extends('workspace.account-layout')
@section('title', 'Đăng nhập H5P Hub')
@section('content')
<section class="hub-card hub-login"><h1>Đăng nhập H5P Hub</h1><p>Nhập tài khoản và mật khẩu để tiếp tục.</p>
<form class="hub-form" method="post" action="{{ route('account.authenticate') }}">@csrf
<div class="hub-field"><label for="username">Tên đăng nhập</label><input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus maxlength="100"></div>
<div class="hub-field"><label for="password">Mật khẩu</label><input id="password" type="password" name="password" autocomplete="current-password" required maxlength="255"></div><button class="hub-button">Đăng nhập</button></form>
</section>
@endsection
