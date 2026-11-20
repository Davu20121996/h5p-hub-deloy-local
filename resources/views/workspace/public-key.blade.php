<h2>RSA public key</h2>
<p>JWKS URL: <a href="{{ route('lti.jwks') }}" target="_blank" rel="noopener">{{ route('lti.jwks') }}</a></p>
@if($publicKey)
<div class="hub-field"><label for="rsa-public-key">Sao chép khóa công khai (PEM) để cấu hình LMS</label><textarea id="rsa-public-key" rows="10" readonly>{{ $publicKey }}</textarea></div>
@else
@if(auth()->user()?->is_admin && request()->routeIs('admin.*'))
<p>Chưa có khóa RSA. Tạo khóa để cấu hình kết nối LMS. Khóa này dùng chung cho các đơn vị trong H5P Hub.</p>
<form method="post" action="{{ route('admin.keys.generate') }}">@csrf<button class="hub-button">Tạo khóa RSA</button></form>
@else
<p>Chưa có khóa RSA. Liên hệ admin hệ thống để tạo khóa tại mục Kết nối LTI.</p>
@endif
@endif
