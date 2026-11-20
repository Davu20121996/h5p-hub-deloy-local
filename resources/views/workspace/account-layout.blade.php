<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title', 'H5P Hub')</title><link rel="stylesheet" href="{{ asset('hub-forms.css') }}?v={{ filemtime(public_path('hub-forms.css')) }}"></head>
<body class="hub-page"><header class="hub-header"><a href="{{ route('account.index') }}">H5P Hub</a>
@auth<span>{{ auth()->user()->name }} · {{ auth()->user()->role === 'tenant-admin' ? 'Tenant-admin' : 'Giáo viên' }}</span><form method="post" action="{{ route('account.logout') }}">@csrf<button class="hub-button hub-button--secondary">Đăng xuất</button></form>@endauth</header>
<main class="hub-main">
@if(session('status'))<div class="hub-notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="hub-notice hub-notice--error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</main></body></html>
