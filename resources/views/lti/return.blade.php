<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>Quay lại website</title></head>
<body><form id="return" method="POST" action="{{ $returnUrl }}"><input type="hidden" name="JWT" value="{{ $jwt }}"><button type="submit">Hoàn tất và quay lại website</button></form>
<script>document.getElementById('return').submit();</script></body></html>
