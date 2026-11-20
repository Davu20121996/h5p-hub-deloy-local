@extends('admin.layout')
@section('content')
<h1>Quản trị H5P Hub</h1>
<section class="hub-card hub-stats"><div><strong>{{ $tenantCount }}</strong>Đơn vị</div><div><strong>{{ $contentCount }}</strong>Bài H5P</div><div><strong>{{ $platforms->total() }}</strong>Kết nối LMS</div><div><strong>{{ $libraries->total() }}</strong>Thư viện</div></section>
<section class="hub-card"><h2>Kết nối LTI</h2><div class="hub-table"><table><thead><tr><th>Tên LMS</th><th>Issuer</th><th>Client ID</th><th>Trạng thái</th></tr></thead><tbody>@forelse($platforms as $platform)<tr><td>{{ $platform->name }}</td><td>{{ $platform->issuer }}</td><td>{{ $platform->client_id }}</td><td>{{ $platform->active ? 'Hoạt động' : 'Đã tắt' }}</td></tr>@empty<tr><td colspan="4">Chưa có kết nối LMS.</td></tr>@endforelse</tbody></table></div>{{ $platforms->links() }}
<h3>Thêm hoặc cập nhật kết nối</h3><p class="muted">Tải lên file JSON theo cấu hình của lệnh lti:register. Chỉ nhập cấu hình LMS đáng tin cậy. Cập nhật kết nối dùng cùng issuer và client_id; không chuyển deployment sang đơn vị khác.</p>
<details><summary>Cấu trúc JSON</summary><pre style="overflow:auto">{
  "tenant_slug": "school-a",
  "tenant_name": "Trường A",
  "name": "Moodle",
  "issuer": "https://lms.example.com",
  "client_id": "your-client-id",
  "authorization_url": "https://lms.example.com/authorize",
  "jwks_url": "https://lms.example.com/jwks",
  "deployment_id": "your-deployment-id",
  "allowed_origins": ["https://lms.example.com"],
  "tenant_active": true,
  "platform_active": true,
  "deployment_active": true
}</pre></details>
<form class="hub-form hub-form--inline" method="post" action="{{ route('admin.register') }}" enctype="multipart/form-data">@csrf<div class="hub-field"><label for="registration">File cấu hình JSON (tối đa 1 MB)</label><input id="registration" type="file" name="registration" accept=".json" required></div><button class="hub-button">Lưu kết nối</button></form>
<h3>Thông tin cấu hình trên LMS</h3><p>Login URL: <code>{{ route('lti.login') }}</code></p><p>Launch / Redirect / Deep Linking URL: <code>{{ route('lti.launch') }}</code></p><p>Public JWKS: <code>{{ route('lti.jwks') }}</code></p></section>
<section class="hub-card"><h2>Thư viện H5P</h2><div class="hub-table"><table><thead><tr><th>Thư viện</th><th>Phiên bản</th></tr></thead><tbody>@forelse($libraries as $library)<tr><td>{{ $library->machine_name }}</td><td>{{ $library->major_version }}.{{ $library->minor_version }}.{{ $library->patch_version }}</td></tr>@empty<tr><td colspan="2">Chưa cài thư viện.</td></tr>@endforelse</tbody></table></div>{{ $libraries->links() }}
<form class="hub-form hub-form--inline" method="post" action="{{ route('admin.install') }}" enctype="multipart/form-data">
    @csrf
    <div class="hub-field">
        <label for="package">Gói .h5p chứa thư viện (tối đa 50 MB)</label>
        <input id="package" name="package" type="file" accept=".h5p" aria-describedby="package-help" required>
    </div>
    <button class="hub-button">Cài thư viện</button>
</form>
<p class="muted" id="package-help">Chỉ cài gói từ nguồn đáng tin cậy.</p>
</section>
@endsection
