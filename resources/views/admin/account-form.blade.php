<form class="hub-form hub-form--grid hub-form--accounts" method="post" action="{{ $accountStoreUrl }}">@csrf
@if(isset($accountDeployments))
<div class="hub-field"><label for="account-deployment">Kết nối của tenant-admin</label><select id="account-deployment" name="deployment_id" required>
@foreach($accountDeployments as $connection)<option value="{{ $connection->id }}" @selected((string) old('deployment_id') === (string) $connection->id)>{{ $connection->platform->name }} · {{ $connection->deployment_id }}</option>@endforeach
</select></div>
@endif
<div class="hub-field"><label for="account-name">Họ tên</label><input id="account-name" name="name" value="{{ old('name') }}" maxlength="255" required></div>
<div class="hub-field"><label for="account-email">Email</label><input id="account-email" type="email" name="email" value="{{ old('email') }}" maxlength="255" required></div>
<div class="hub-field"><label for="account-username">Tên đăng nhập</label><input id="account-username" name="username" value="{{ old('username') }}" maxlength="100" autocomplete="off" pattern="[A-Za-z0-9_.@+\-]+" required><small>Có thể dùng email, ví dụ: giaovien@truong.vn. Không dùng khoảng trắng; tên đăng nhập là duy nhất trong hệ thống.</small></div>
<div class="hub-field"><label for="account-password">Mật khẩu (ít nhất 12 ký tự)</label><input id="account-password" type="password" name="password" minlength="12" maxlength="255" autocomplete="new-password" required></div>
<div class="hub-field"><label for="account-confirmation">Nhập lại mật khẩu</label><input id="account-confirmation" type="password" name="password_confirmation" minlength="12" maxlength="255" autocomplete="new-password" required></div>
<div class="hub-actions"><button class="hub-button">{{ $accountSubmitLabel }}</button></div></form>
<p>Địa chỉ đăng nhập: <a href="{{ route('account.login') }}">{{ route('account.login') }}</a>. Gửi tên đăng nhập và mật khẩu cho người nhận sau khi cấp tài khoản.</p>
