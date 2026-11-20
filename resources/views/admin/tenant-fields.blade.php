<form class="hub-form hub-form--grid" method="post" action="{{ $tenantFormAction ?? ($tenant->exists ? route('admin.tenants.update', $tenant) : route('admin.tenants.store')) }}">
@csrf @if($tenant->exists) @method('put') @endif
<div class="hub-field"><label for="slug">Mã đơn vị</label><input id="slug" name="slug" @readonly($tenantSelfService ?? false) value="{{ old('slug', $tenant->slug) }}" required maxlength="100" pattern="[a-zA-Z0-9_-]+"></div>
<div class="hub-field"><label for="name">Tên đơn vị</label><input id="name" name="name" value="{{ old('name', $tenant->name) }}" required maxlength="255"></div>
<div class="hub-field"><label for="contact_name">Người liên hệ</label><input id="contact_name" name="contact_name" value="{{ old('contact_name', $tenant->contact_name) }}" maxlength="255"></div>
<div class="hub-field"><label for="contact_email">Email liên hệ</label><input id="contact_email" type="email" name="contact_email" value="{{ old('contact_email', $tenant->contact_email) }}" maxlength="255"></div>
<div class="hub-field"><label for="contact_phone">Điện thoại</label><input id="contact_phone" name="contact_phone" value="{{ old('contact_phone', $tenant->contact_phone) }}" maxlength="50"></div>
<div class="hub-field"><label for="notes">Ghi chú</label><textarea id="notes" name="notes" rows="4" maxlength="10000">{{ old('notes', $tenant->notes) }}</textarea></div>
@unless($tenantSelfService ?? false)
<div class="hub-field"><label for="active">Trạng thái</label><select id="active" name="active"><option value="1" @selected((string)old('active', (int)$tenant->active) === '1')>Hoạt động</option><option value="0" @selected((string)old('active', (int)$tenant->active) === '0')>Đã khóa</option></select></div>
<p class="muted hub-field--wide">Khóa đơn vị sẽ chặn cả truy cập LTI mới và phiên đang hoạt động. Dữ liệu được giữ lại.</p>@endunless
<div class="hub-actions"><button class="hub-button">Lưu đơn vị</button></div>
</form>
