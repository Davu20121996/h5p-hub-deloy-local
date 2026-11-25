<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'H5P Hub')</title>
    <link rel="stylesheet" href="{{ asset('hub-forms.css') }}?v={{ filemtime(public_path('hub-forms.css')) }}">
    @yield('head')
</head>
<body class="hub-page">
<header class="hub-header">
    <strong>H5P Hub · {{ $launch->deployment->tenant->name }}</strong>
    <span>{{ $launch->identity->name ?? $launch->identity->subject }} · {{ $launch->canManageTenant() ? 'Tenant-admin' : ($launch->can_author ? 'Giảng viên' : 'Học viên') }}</span>
</header>
<main class="hub-main">
    @if($launch->can_author)<nav class="hub-nav"><a href="{{ route('workspace.index', $launch) }}">Kho bài H5P</a>@if($launch->canManageTenant())<a href="{{ route('workspace.tenant', $launch) }}">Quản trị đơn vị</a>@endif</nav>@endif
    @if(session('status'))<div class="hub-notice" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="hub-notice hub-notice--error" role="alert">
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('body')
</main>
@yield('scripts')
</body>
</html>
