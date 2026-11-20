<form method="post" action="{{ route('content.import', ['launch' => $launch->id]) }}" enctype="multipart/form-data" class="hub-form">
@csrf
<div class="hub-field"><label for="workspace-package">Tệp bài học .h5p</label><input id="workspace-package" type="file" name="package" accept=".h5p" aria-describedby="workspace-package-help" required></div>
<p id="workspace-package-help" class="muted">Chọn bài đã xuất từ H5P, tối đa 50 MB. Nếu loại bài chưa được hỗ trợ, hãy nhờ admin hệ thống cài thư viện trước.</p>
<label class="hub-check"><input type="checkbox" name="publish" value="1" @checked(old('publish', true))> Xuất bản sau khi nhập</label>
<div class="hub-actions"><button class="hub-button">Nhập bài .h5p</button></div>
</form>
