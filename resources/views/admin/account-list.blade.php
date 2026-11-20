<div class="hub-table"><table><thead><tr><th>Họ tên / Email</th><th>Tên đăng nhập</th><th>Trạng thái</th><th>Quản lý</th></tr></thead><tbody>
@forelse($accounts as $account)
<tr><td>{{ $account->name }}<br>{{ $account->email }}</td><td>{{ $account->username }}</td><td>{{ $account->active ? 'Hoạt động' : 'Đã khóa' }}</td><td>
<details><summary>Khóa / Đặt lại mật khẩu</summary><form class="hub-form" method="post" action="{{ request()->routeIs('admin.*') ? route('admin.tenants.accounts.update', [$tenant, $account]) : route('account.teachers.update', $account) }}">@csrf @method('patch')
<div class="hub-field"><label for="account-active-{{ $account->id }}">Trạng thái</label><select id="account-active-{{ $account->id }}" name="active"><option value="1" @selected($account->active)>Hoạt động</option><option value="0" @selected(!$account->active)>Đã khóa</option></select></div>
<div class="hub-field"><label for="new-password-{{ $account->id }}">Mật khẩu mới (để trống nếu giữ nguyên)</label><input id="new-password-{{ $account->id }}" type="password" name="password" autocomplete="new-password" minlength="12" maxlength="255"></div>
<div class="hub-field"><label for="confirm-password-{{ $account->id }}">Nhập lại mật khẩu mới</label><input id="confirm-password-{{ $account->id }}" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="255"></div>
<button class="hub-button">Lưu tài khoản</button></form></details></td></tr>
@empty<tr><td colspan="4">Chưa có tài khoản.</td></tr>@endforelse
</tbody></table></div>
