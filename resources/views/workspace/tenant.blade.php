@extends('workspace.layout')
@section('title', 'Quản trị đơn vị')
@section('body')
<h1>Quản trị đơn vị</h1>
<section class="hub-card"><h2>Người dùng LTI</h2>
@if($canEditRoles)
<p>Quyền đã cấp được giữ khi người dùng đăng nhập lại từ LMS và chỉ áp dụng trong đơn vị này.</p>
@else
<p>Quản lý tài khoản trên Client. Admin hệ thống cấu hình quyền H5P trên HUB.</p>
@endif
<div class="hub-table"><table><thead><tr><th>Người dùng LMS</th><th>Quyền trong đơn vị</th>@if($canEditRoles)<th>Thao tác</th>@endif</tr></thead><tbody>
@forelse($members as $member)
<tr><td>{{ $member->name ?? $member->subject }}<br>{{ $member->email }}<br>Subject: {{ $member->subject }}</td>
<td>{{ $member->local_role ?? 'Theo LMS' }}</td>
@if($canEditRoles)
<td>
@if($member->local_role !== 'tenant-admin' && $member->lti_identity_id !== $launch->lti_identity_id)
<form class="hub-form" method="post" action="{{ route('workspace.tenant.role', [$launch, $member->lti_identity_id]) }}">
@csrf @method('patch')
<label for="member-role-{{ $member->lti_identity_id }}">Quyền</label><select id="member-role-{{ $member->lti_identity_id }}" name="local_role"><option value="teacher" @selected($member->local_role === 'teacher')>Giáo viên</option><option value="learner" @selected($member->local_role === 'learner')>Học viên</option></select><button class="hub-button">Lưu quyền</button></form>
@endif
</td>
@endif
</tr>
@empty<tr><td colspan="{{ $canEditRoles ? 3 : 2 }}">Chưa có người dùng. Người dùng được ghi nhận khi mở H5P từ LMS.</td></tr>@endforelse
</tbody></table></div>{{ $members->links() }}</section>
<section class="hub-card">@include('workspace.public-key')</section>
@endsection
