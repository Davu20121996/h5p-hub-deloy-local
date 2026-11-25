@php($platform = $deployment?->platform)
@php($prefix = $deployment ? 'connection-'.$deployment->id.'-' : 'new-connection-')
<form class="hub-form hub-form--grid" method="post" action="{{ $deployment ? route('admin.tenants.connections.update', [$tenant, $deployment]) : route('admin.tenants.connections.store', $tenant) }}">
@csrf @if($deployment) @method('put') @endif
@foreach(['name' => 'Tên LMS', 'issuer' => 'Issuer', 'client_id' => 'Client ID', 'authorization_url' => 'Authorization URL', 'jwks_url' => 'JWKS URL'] as $field => $label)
<div class="hub-field"><label for="{{ $prefix.$field }}">{{ $label }}</label><input id="{{ $prefix.$field }}" name="{{ $field }}" value="{{ $platform?->$field }}" required maxlength="{{ $field === 'issuer' ? 512 : (in_array($field, ['authorization_url', 'jwks_url']) ? 2048 : 255) }}" @readonly($deployment && in_array($field, ['issuer', 'client_id']))></div>
@endforeach
<div class="hub-field"><label for="{{ $prefix }}deployment_id">Deployment ID</label><input id="{{ $prefix }}deployment_id" name="deployment_id" value="{{ $deployment?->deployment_id }}" required maxlength="255" @readonly($deployment)></div>
<div class="hub-field"><label for="{{ $prefix }}origins">Allowed origins (mỗi dòng một origin)</label><textarea id="{{ $prefix }}origins" name="allowed_origins" rows="3" required maxlength="10000">{{ implode("\n", $platform?->allowed_origins ?? []) }}</textarea></div>
<div class="hub-field"><label for="{{ $prefix }}platform_active">Trạng thái LMS</label><select id="{{ $prefix }}platform_active" name="platform_active"><option value="1" @selected(!$platform || $platform->active)>Hoạt động</option><option value="0" @selected($platform && !$platform->active)>Đã tắt</option></select></div>
<div class="hub-field"><label for="{{ $prefix }}active">Trạng thái deployment của đơn vị</label><select id="{{ $prefix }}active" name="active"><option value="1" @selected(!$deployment || $deployment->active)>Hoạt động</option><option value="0" @selected($deployment && !$deployment->active)>Đã tắt</option></select></div>
<p class="muted hub-field--wide">Issuer + Client ID xác định LMS dùng chung. Thay đổi thông tin/trạng thái LMS sẽ áp dụng cho mọi đơn vị dùng LMS đó. Deployment chỉ áp dụng cho đơn vị này. Không thể chuyển deployment sang đơn vị khác.</p><div class="hub-actions"><button class="hub-button">Lưu kết nối</button></div></form>
