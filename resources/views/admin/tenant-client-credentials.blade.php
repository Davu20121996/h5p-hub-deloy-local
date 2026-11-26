@extends('admin.layout')

@section('content')
<section class="hub-card">
    <h1>Tài khoản Tenant Admin của {{ $tenant->name }}</h1>
    <dl>
        <dt>Email đăng nhập Client</dt><dd>{{ $email }}</dd>
        @if($password)
            <dt>Mật khẩu ban đầu</dt><dd><code>{{ $password }}</code></dd>
        @endif
    </dl>
    @if($password)
        <p>Chuyển mật khẩu qua kênh riêng. Tenant Admin đăng nhập Client và đổi mật khẩu trong mục Tài khoản.</p>
    @else
        <p>Client đã có tài khoản này và đã đồng bộ kết nối LTI.</p>
    @endif
    <a href="{{ $clientUrl }}">Mở Client</a>
</section>
@endsection
