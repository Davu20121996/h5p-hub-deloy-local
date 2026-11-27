@extends('admin.layout')
@section('content')
<h1>{{ $tenant->name }}</h1><p>Mã: {{ $tenant->slug }} · {{ $tenant->active ? 'Hoạt động' : 'Đã khóa' }} · Tạo ngày {{ $tenant->created_at->format('d/m/Y H:i') }}</p>
<nav class="hub-nav hub-tabs" aria-label="Đơn vị">@foreach(['profile'=>'Hồ sơ', 'connections'=>'Kết nối LTI', 'users'=>'Người dùng LMS', 'contents'=>'Bài H5P'] as $key=>$label)<a href="{{ route('admin.tenants.show', [$tenant, 'tab'=>$key]) }}" @if(request('tab', 'profile') === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
@php($clientDeployments = $deployments->filter(fn ($deployment) => $deployment->active && $deployment->platform->active && \App\Lti\Claims::isManagedClient($deployment->platform->issuer)))
@if(request('tab', 'profile') === 'profile')
@if($tenant->active && $clientDeployments->isNotEmpty())
<section class="hub-card">
    <h2>Cấp tài khoản Tenant Admin trên Client</h2>
    <form class="hub-form hub-form--grid" method="post" action="{{ route('admin.tenants.client-credentials', $tenant) }}">
        @csrf
        <div class="hub-field"><label for="manager-name">Tên Tenant Admin</label><input id="manager-name" name="manager_name" value="{{ old('manager_name', $tenant->contact_name) }}" maxlength="255" required></div>
        <div class="hub-field"><label for="manager-email">Email đăng nhập Client</label><input id="manager-email" type="email" name="manager_email" value="{{ old('manager_email', $tenant->contact_email) }}" maxlength="255" required></div>
        <div class="hub-field">
            <label for="client-deployment">Kết nối Client</label>
            <select id="client-deployment" name="deployment" required>
                @foreach($clientDeployments as $deployment)
                    <option value="{{ $deployment->id }}" @selected((int) old('deployment') === $deployment->id)>{{ $deployment->deployment_id }}</option>
                @endforeach
            </select>
        </div>
        <div class="hub-actions"><button class="hub-button">Cấp tài khoản và đồng bộ LTI</button></div>
    </form>
</section>
@elseif($tenant->active && config('lti.managed_client_url'))
<p><a href="{{ route('admin.tenants.show', [$tenant, 'tab' => 'connections']) }}">Thêm kết nối LTI của Client</a> trước khi cấp tài khoản.</p>
@endif
<section class="hub-card"><h2>Hồ sơ đơn vị</h2>@include('admin.tenant-fields')</section>
@elseif(request('tab') === 'connections')
<section class="hub-card"><h2>Thông tin cấu hình trên LMS</h2><p>Người dùng đăng nhập vào HUB qua LTI. Quyền trong HUB được thiết lập tại tab Người dùng LMS.</p><p>Login URL: <code>{{ route('lti.login') }}</code></p><p>Launch / Redirect / Deep Linking: <code>{{ route('lti.launch') }}</code></p><p>Public JWKS: <code>{{ route('lti.jwks') }}</code></p>@include('workspace.public-key', ['publicKey' => app(\App\Lti\KeyStore::class)->publicKey()])</section>
@foreach($deployments as $deployment)<section class="hub-card"><h2>{{ $deployment->platform->name }} · {{ $deployment->deployment_id }}</h2>@include('admin.connection-form', ['deployment'=>$deployment])</section>@endforeach
<section class="hub-card"><h2>Thêm kết nối LTI</h2>@include('admin.connection-form', ['deployment'=>null])</section>
@elseif(request('tab') === 'users')
<section class="hub-card">
    <h2>Thêm người dùng và cấp quyền từ LMS</h2>
    <p class="muted">Dùng Subject LTI để thêm người dùng đã có tài khoản. Với tên đăng nhập thêm thủ công như gvsmb, chọn Đồng bộ tài khoản Client ở dòng người dùng.</p>
    @php($authorDeployments = $deployments->filter(fn ($deployment) => $deployment->active && $deployment->platform->active))
    @if($tenant->active && $authorDeployments->isNotEmpty())
        <form class="hub-form hub-form--grid" method="post" action="{{ route('admin.tenants.users.store', $tenant) }}">
            @csrf
            <div class="hub-field"><label for="author-deployment">Kết nối LMS</label>
            <select id="author-deployment" name="author_deployment" required>
                @foreach($authorDeployments as $deployment)
                    <option value="{{ $deployment->id }}" @selected((string) old('author_deployment') === (string) $deployment->id)>{{ $deployment->platform->name }} · {{ $deployment->deployment_id }}</option>
                @endforeach
            </select></div>
            <div class="hub-field"><label for="author-subject">ID người dùng LMS (Subject LTI)</label>
            <input id="author-subject" name="author_subject" value="{{ old('author_subject') }}" maxlength="255"
                @error('author_subject') aria-invalid="true" aria-describedby="author-subject-error" @enderror required>
            @error('author_subject')<small class="hub-field-error" id="author-subject-error">{{ $message }}</small>@enderror
            </div>
            <div class="hub-field"><label for="author-name">Tên hiển thị (không bắt buộc)</label>
            <input id="author-name" name="author_name" value="{{ old('author_name') }}" maxlength="255"></div>
            <div class="hub-field"><label for="author-role">Quyền trong đơn vị</label><select id="author-role" name="author_role"><option value="teacher">Giáo viên</option><option value="tenant-admin" @selected(old('author_role') === 'tenant-admin')>Tenant-admin</option></select></div>
            <div class="hub-actions"><button class="hub-button">Thêm và cấp quyền</button></div>
        </form>
    @else
        <p>Cần đơn vị và ít nhất một <a href="{{ route('admin.tenants.show', [$tenant, 'tab' => 'connections']) }}">kết nối LTI</a> đang hoạt động.</p>
    @endif
</section>
<section class="hub-card"><h2>Người dùng tự đồng bộ</h2><p class="muted">Được ghi nhận sau khi truy cập LTI hợp lệ. Tên/email phụ thuộc dữ liệu LMS cung cấp; không cần tạo tài khoản thủ công.</p><form class="hub-form hub-form--filter" method="get"><input type="hidden" name="tab" value="users"><div class="hub-field"><label for="q">Tìm tên, email, ID người dùng LMS</label><input id="q" name="q" value="{{ request('q') }}"></div><div class="hub-field"><label for="role">Vai trò LTI</label><select id="role" name="role"><option value="">Tất cả</option>@foreach($roleOptions as $role)<option value="{{ $role }}" @selected(request('role') === $role)>{{ $role }}</option>@endforeach</select></div><button class="hub-button">Tìm kiếm</button></form></section>
<section class="hub-card"><div class="hub-table"><table><thead><tr><th>Người dùng</th><th>LMS / ID</th><th>Vai trò gần nhất</th><th>Truy cập đầu / cuối</th><th>Chi tiết</th></tr></thead><tbody>@forelse($members as $member)<tr><td>{{ $member->name ?? 'Chưa cung cấp tên' }}<br>{{ $member->email ?? 'Chưa cung cấp email' }}</td><td>{{ $member->platform_name }}<br>{{ $member->subject }}</td><td>@foreach(json_decode($member->roles, true) ?? [] as $role)<div>{{ $role }}</div>@endforeach</td><td>{{ $member->first_access_at }}<br>{{ $member->last_access_at }}</td><td><a href="{{ route('admin.tenants.show', [$tenant, 'tab'=>'contents', 'user'=>$member->lti_identity_id]) }}">Bài của người dùng</a><details><summary>Định danh</summary>ID nội bộ: {{ $member->lti_identity_id }}<br>Subject LMS: {{ $member->subject }}</details>
<form class="hub-form" method="post" action="{{ route('admin.tenants.users.role', [$tenant, $member->lti_identity_id]) }}">
@csrf @method('patch')
<label for="role-{{ $member->lti_identity_id }}">Quyền trong đơn vị</label>
<select id="role-{{ $member->lti_identity_id }}" name="local_role">
@foreach(['lms' => 'Theo LMS', 'tenant-admin' => 'Tenant-admin', 'teacher' => 'Giáo viên', 'learner' => 'Học viên'] as $value => $label)
<option value="{{ $value }}" @selected(($member->local_role ?? 'lms') === $value)>{{ $label }}</option>
@endforeach
</select><button class="hub-button hub-button--secondary">Lưu quyền</button></form>
@if($member->first_access_at === null)
    @php($matchingDeployments = $clientDeployments->where('lti_platform_id', $member->lti_platform_id))
    @if(\App\Lti\Claims::isManagedClient($member->platform_issuer) && ctype_digit($member->subject))
        <p class="muted">Đã có Subject Client · Chưa truy cập từ LMS</p>
    @else
        <p class="muted">Đã thêm thủ công · Chưa truy cập từ LMS</p>
    @endif
    @if($tenant->active && $matchingDeployments->isNotEmpty() && $member->local_role !== 'learner' && !ctype_digit($member->subject)
        && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}\z/', $member->subject))
        <form class="hub-form" method="post" action="{{ route('admin.tenants.users.client-sync', [$tenant, $member->lti_identity_id]) }}">
            @csrf
            <label for="sync-deployment-{{ $member->lti_identity_id }}">Kết nối Client</label>
            <select id="sync-deployment-{{ $member->lti_identity_id }}" name="deployment" required>
                @foreach($matchingDeployments as $deployment)
                    <option value="{{ $deployment->id }}">{{ $deployment->deployment_id }}</option>
                @endforeach
            </select>
            <label for="sync-role-{{ $member->lti_identity_id }}">Vai trò trên Client</label>
            <select id="sync-role-{{ $member->lti_identity_id }}" name="account_role" required>
                <option value="">Chọn vai trò</option>
                <option value="teacher" @selected($member->local_role === 'teacher')>Giáo viên</option>
                <option value="tenant_admin" @selected($member->local_role === 'tenant-admin')>Tenant Admin</option>
            </select>
            <button class="hub-button">Đồng bộ tài khoản Client</button>
        </form>
    @endif
@endif
@if(($member->first_access_at === null && $member->local_role !== 'learner') || \App\Lti\Claims::canAuthorForPlatform(json_decode($member->roles, true) ?? [], $member->local_role, $member->platform_issuer))
<details class="hub-inline-panel">
    <summary>Nhập bài .h5p</summary>
    <form class="hub-form" method="post" enctype="multipart/form-data" action="{{ route('admin.tenants.contents.import', [$tenant, $member->lti_identity_id]) }}">
        @csrf
        <div class="hub-field"><label for="package-{{ $member->lti_identity_id }}">Gói bài đã cài thư viện</label>
        <input id="package-{{ $member->lti_identity_id }}" type="file" name="package" accept=".h5p" required></div>
        <label class="hub-check"><input type="checkbox" name="publish" value="1"> Xuất bản ngay</label>
        <button class="hub-button">Nhập bài cho người dùng</button>
    </form>
</details>
@endif
</td></tr>@empty<tr><td colspan="5">Không có người dùng phù hợp.</td></tr>@endforelse</tbody></table></div>{{ $members->links() }}</section>
@elseif(request('tab') === 'contents')
<section class="hub-card"><h2>Bài H5P của đơn vị</h2><form class="hub-form hub-form--filter" method="get"><input type="hidden" name="tab" value="contents">@if(request('user'))<input type="hidden" name="user" value="{{ request('user') }}"><p class="hub-field--wide">Đang lọc tác giả ID {{ request('user') }}. <a href="{{ route('admin.tenants.show', [$tenant, 'tab'=>'contents']) }}">Bỏ lọc tác giả</a></p>@endif<div class="hub-field"><label for="q">Tìm tiêu đề</label><input id="q" name="q" value="{{ request('q') }}"></div><div class="hub-field"><label for="status">Trạng thái</label><select id="status" name="status"><option value="">Tất cả</option>@foreach(['draft'=>'Bản nháp', 'published'=>'Đã xuất bản', 'hidden'=>'Admin đã ẩn'] as $value=>$label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div><div class="hub-field"><label for="library">Loại nội dung / phiên bản</label><select id="library" name="library"><option value="">Tất cả</option>@foreach($libraries as $library)<option value="{{ $library->id }}" @selected((string)request('library') === (string)$library->id)>{{ $library->machine_name }} {{ $library->major_version }}.{{ $library->minor_version }}.{{ $library->patch_version }}</option>@endforeach</select></div><button class="hub-button">Tìm kiếm</button></form></section>
<section class="hub-card"><div class="hub-table"><table><thead><tr><th>Bài / tác giả</th><th>Loại / phiên bản</th><th>Trạng thái</th><th>Tạo / cập nhật</th><th>Dung lượng nội dung</th><th>Thao tác</th></tr></thead><tbody>@forelse($contents as $content)<tr><td>{{ $content->title }}<br><small>{{ $authors->get($content->lti_identity_id)?->name ?? $authors->get($content->lti_identity_id)?->subject }}</small></td><td>{{ $content->library->machine_name }}<br>{{ $content->library->major_version }}.{{ $content->library->minor_version }}.{{ $content->library->patch_version }}</td><td>{{ $content->admin_hidden ? 'Admin đã ẩn' : ($content->status === 'published' ? 'Đã xuất bản' : 'Bản nháp') }}</td><td>{{ $content->created_at->format('d/m/Y H:i') }}<br>{{ $content->updated_at->format('d/m/Y H:i') }}</td><td>{{ number_format($sizes[$content->id] / 1024, 1) }} KB</td><td><a href="{{ route('admin.tenants.preview', [$tenant, $content]) }}">Xem trước</a>
@if($content->status === 'published' && !$content->admin_hidden)
<details class="hub-inline-panel"><summary>Target Link URI</summary>
<div class="hub-field"><label for="target-{{ $content->id }}">URL hoạt động trên LMS</label>
<input id="target-{{ $content->id }}" value="{{ route('lti.launch', ['content_id' => $content->uuid]) }}" readonly></div>
</details>
@endif
<br><a href="{{ route('admin.tenants.export', [$tenant, $content]) }}">Tải .h5p</a><form method="post" action="{{ route('admin.tenants.visibility', [$tenant, $content]) }}">@csrf @method('patch')<input type="hidden" name="admin_hidden" value="{{ $content->admin_hidden ? '0' : '1' }}"><button class="hub-button hub-button--secondary hub-button--small">{{ $content->admin_hidden ? 'Bỏ ẩn' : 'Ẩn bài' }}</button></form></td></tr>@empty<tr><td colspan="6">Không có bài phù hợp.</td></tr>@endforelse</tbody></table></div><p class="muted">Dung lượng gồm dữ liệu nội dung và tệp đính kèm, không gồm thư viện dùng chung. Bài admin ẩn sẽ bị chặn truy cập từ LMS.</p>{{ $contents->links() }}</section>
@endif
@endsection
