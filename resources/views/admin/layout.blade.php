<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Quản trị · H5P Hub</title>
    <link rel="stylesheet" href="{{ asset('hub-forms.css') }}?v={{ filemtime(public_path('hub-forms.css')) }}">
</head>
<body class="hub-page hub-admin">
<header class="hub-header">
    <a href="{{ route('admin.index') }}">H5P Hub / Quản trị</a>
    @auth
        <form method="post" action="{{ route('admin.logout') }}">
            @csrf
            <button class="hub-button hub-button--secondary hub-button--small">Đăng xuất</button>
        </form>
    @endauth
</header>
<main class="hub-main">
    @auth
        <nav class="hub-nav" aria-label="Quản trị">
            <a href="{{ route('admin.index') }}" @if(request()->routeIs('admin.index')) aria-current="page" @endif>Tổng quan & thư viện</a>
            <a href="{{ route('admin.tenants.index') }}" @if(request()->routeIs('admin.tenants.*')) aria-current="page" @endif>Quản lý đơn vị</a>
        </nav>
    @endauth
    @if(session('status'))
        <div class="hub-notice" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="hub-notice hub-notice--error" role="alert">
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>
@yield('scripts')
</body>
</html>
