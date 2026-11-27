@extends('admin.layout')

@section('content')
<section class="hub-card">
    <h1>Tài khoản Client của {{ $tenant->name }}</h1>
    <dl>
        <dt>{{ $loginLabel }}</dt><dd>{{ $login }}</dd>
        @isset($subject)
            <dt>Subject LTI</dt><dd>{{ $subject }}</dd>
        @endisset
        @if($password)
            <dt>Mật khẩu ban đầu</dt><dd><code>{{ $password }}</code></dd>
        @endif
    </dl>
    @if($password)
        <p>Chuyển mật khẩu qua kênh riêng. Người dùng đăng nhập Client và đổi mật khẩu trong mục Tài khoản.</p>
    @else
        <p>Client đã có tài khoản này và đã đồng bộ kết nối LTI.</p>
    @endif
    <a href="{{ $clientUrl }}">Mở Client</a>
    <a href="{{ route('admin.tenants.show', [$tenant, 'tab' => 'users']) }}">Về danh sách người dùng</a>
</section>
@endsection
