@extends('workspace.account-layout')
@section('title', 'Quản trị đơn vị')
@section('content')
<h1>{{ auth()->user()->tenant->name }}</h1>
<nav class="hub-nav hub-tabs" aria-label="Quản trị đơn vị">
@foreach(['profile' => 'Hồ sơ đơn vị', 'connections' => 'Kết nối LMS', 'teachers' => 'Giáo viên', 'contents' => 'Bài H5P của đơn vị'] as $tab => $label)
<a href="{{ route('account.index', ['tab' => $tab]) }}" @if(request('tab', 'teachers') === $tab) aria-current="page" @endif>{{ $label }}</a>
@endforeach
<a href="{{ route('account.workspace') }}">Nhập tệp .h5p</a></nav>
@if(request('tab') === 'profile')
<section class="hub-card"><h2>Hồ sơ đơn vị</h2>@include('admin.tenant-fields', ['tenantFormAction' => route('account.tenant.update'), 'tenantSelfService' => true])</section>
@elseif(request('tab') === 'connections')
<section class="hub-card"><h2>Thông tin cấu hình trên LMS</h2>
<p>Login URL: <code>{{ route('lti.login') }}</code></p><p>Launch / Redirect URL: <code>{{ route('lti.launch') }}</code></p>
@include('workspace.public-key')</section>
@foreach($deployments as $deployment)<section class="hub-card"><h2>{{ $deployment->platform->name }}</h2>
<dl>@foreach(['issuer' => 'Issuer', 'client_id' => 'Client ID', 'authorization_url' => 'Authorization URL', 'jwks_url' => 'JWKS URL', 'ags_token_url' => 'AGS Token URL'] as $field => $label)<dt><strong>{{ $label }}</strong></dt><dd><code>{{ $deployment->platform->$field ?? 'Chưa cấu hình' }}</code></dd>@endforeach
<dt><strong>Deployment ID</strong></dt><dd>{{ $deployment->deployment_id }}</dd><dt><strong>Allowed origins</strong></dt><dd>@foreach($deployment->platform->allowed_origins as $origin)<div><code>{{ $origin }}</code></div>@endforeach</dd>
<dt><strong>Trạng thái</strong></dt><dd>{{ $deployment->active && $deployment->platform->active ? 'Hoạt động' : 'Đã tắt' }}</dd></dl>
<p class="muted">Admin hệ thống quản lý cấu hình kết nối LMS.</p></section>@endforeach
@elseif(request('tab') === 'contents')
<section class="hub-card"><h2>Bài H5P của đơn vị</h2><div class="hub-table"><table><thead><tr><th>Bài H5P</th><th>Loại bài</th><th>Trạng thái / Hiển thị</th><th>Thao tác</th></tr></thead><tbody>
@forelse($contents as $content)<tr><td>{{ $content->title }}</td><td>{{ $content->library->machine_name }}</td><td>
<form class="hub-form" method="post" action="{{ route('account.contents.update', $content) }}">@csrf @method('patch')
<label for="status-{{ $content->id }}">Trạng thái</label><select id="status-{{ $content->id }}" name="status"><option value="draft" @selected($content->status === 'draft')>Bản nháp</option><option value="published" @selected($content->status === 'published')>Đã xuất bản</option></select>
<label for="hidden-{{ $content->id }}">Hiển thị</label><select id="hidden-{{ $content->id }}" name="admin_hidden"><option value="0" @selected(!$content->admin_hidden)>Hiển thị</option><option value="1" @selected($content->admin_hidden)>Ẩn bài</option></select><button class="hub-button hub-button--secondary">Lưu</button></form>
</td><td>@unless($content->admin_hidden)<a href="{{ route('account.contents.preview', $content) }}">Xem trước</a>@endunless</td></tr>
@empty<tr><td colspan="4">Chưa có bài H5P.</td></tr>@endforelse</tbody></table></div>{{ $contents->withQueryString()->links() }}</section>
@else
<section class="hub-card"><h2>Cấp tài khoản giáo viên</h2><p>Tài khoản mới chỉ có quyền giáo viên trong đơn vị này.</p>
@include('admin.account-form', ['accountStoreUrl' => route('account.teachers.store'), 'accountSubmitLabel' => 'Cấp tài khoản giáo viên'])
</section>
<section class="hub-card"><h2>Tài khoản giáo viên</h2>@include('admin.account-list'){{ $accounts->links() }}</section>
@endif
@endsection
