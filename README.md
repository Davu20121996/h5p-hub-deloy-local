# Laravel H5P Hub

Laravel 13 / PHP 8.3, H5P core 1.28, H5P editor 1.25. Một Hub phục vụ nhiều đơn vị; web A/B đóng vai trò **LTI Platform**, Hub đóng vai trò **LTI Tool**.

## Phạm vi đã triển khai

- Đăng ký Platform bằng CLI; nhiều deployment ánh xạ vào tenant.
- OIDC third-party initiated login, JWT RS256, JWKS và đổi khóa.
- Kiểm tra issuer/client ID, deployment, nonce, state gắn với phiên trình duyệt, thời hạn và chống dùng lại.
- Resource Link Launch; ánh xạ vị trí bài trên website với bài H5P.
- Deep Linking 2.0: trainer tạo/chọn bài, trả JWT về URL đã đăng ký.
- Editor/player H5P thật; tạo/sửa, nháp/xuất bản, nhân bản, nhập/xuất .h5p, xóa bài chưa được sử dụng.
- Tệp nội dung riêng theo tenant và kiểm tra phiên/quyền khi đọc.
- Thư viện H5P cài tập trung bằng CLI từ nguồn quản trị tin cậy. Trainer không được cài JavaScript từ gói tải lên.
- Lưu sự kiện kết quả của bài chính, chống trùng theo event ID trong mỗi launch; chủ bài xem kết quả nội bộ.
- Demo local cho hai website/tenant, trainer và learner.

**Không triển khai AGS, NRPS, Dynamic Registration.** Không gửi điểm ra website và không khai báo line item trong Deep Linking.

## Chạy local bằng Docker

Yêu cầu Docker Desktop với Linux containers.

```sh
cp .env.example .env
docker compose build app
docker compose up -d postgres app web
docker compose exec app composer install --no-interaction
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec --user www-data app php artisan lti:keys
docker compose exec --user www-data app php artisan h5p:demo
docker compose up -d worker scheduler
```

Trên PowerShell, dùng `Copy-Item .env.example .env` thay cho `cp`. Chỉ tạo APP_KEY một lần; không tạo lại khi đã có dữ liệu mã hóa.

Mở **http://localhost:8080/demo**. Thử Trainer A/B, tạo bài, xuất bản và chọn bài để hoàn tất Deep Linking; sau đó mở bài dưới vai trò Learner. Demo không có mật khẩu vì chỉ là Platform giả lập. Nó chỉ hoạt động khi APP_ENV=local và LTI_DEMO_ENABLED=true; Compose local bật sẵn. Cổng web chỉ bind 127.0.0.1.

Compose local dùng PostgreSQL và database-backed session/cache/queue. Redis chưa cần cho MVP. Volume PostgreSQL và storage trên workspace phải được giữ lại khi khởi động lại.

## Kết nối web A/B thật

Sửa bản sao `resources/examples/platform-a.json`, sau đó:

```sh
docker compose exec app php artisan lti:register resources/examples/platform-a.json
```

Thông tin cấp cho website:

| Mục | URL |
| --- | --- |
| OIDC initiation / login | https://h5p.example.com/lti/login |
| Redirect / launch URI | https://h5p.example.com/lti/launch |
| Deep Linking target | https://h5p.example.com/lti/launch |
| Tool JWKS | https://h5p.example.com/lti/jwks |

Website cấp issuer, client ID, deployment ID, authorization endpoint và JWKS URL. Đăng ký các URL chính xác; allowed_origins là origin đầy đủ, không có wildcard/path. Một registration có thể có nhiều deployment; deployment đã có không được chuyển sang tenant khác.

Lệnh đăng ký hỗ trợ các trường boolean tùy chọn `tenant_active`, `platform_active`, `deployment_active` để khóa/mở. Nếu bỏ qua, trạng thái hiện có được giữ lại. Quản trị hiện dùng CLI; chưa có dashboard quản trị tài khoản riêng.

### Luồng trainer

Website khởi tạo Deep Linking launch với vai trò Instructor hoặc ContentDeveloper, accept_types chứa ltiResourceLink và document target iframe/window. Hub cho soạn/chọn bài đã xuất bản. Website phải xác thực JWT trả về và lưu resource link. Thông tin data được trả nguyên vẹn.

Hub trả content_id trong custom và trong query của URL bài. Khi launch bài, website phải giữ nguyên target_link_uri tương ứng hoặc chuyển custom.content_id; các claim đều phải nằm trong JWT có chữ ký.

### Luồng learner

Website thực hiện LtiResourceLinkRequest với sub ổn định, roles, deployment_id, resource_link.id và liên kết bài đã lưu. Hub xác định tenant từ deployment đã đăng ký; không lấy tenant_id từ trình duyệt. Resource link đã gắn bài không được đổi sang bài khác.

Danh tính được lưu theo registration + sub, không gộp theo email. Vai trò Instructor không cấp quyền admin và không cho sửa bài của người khác. Learner chỉ đọc bài được launch; trainer xem trước không tạo kết quả học.

## Thư viện H5P

```sh
docker compose exec --user www-data app php artisan h5p:libraries:install /path/to/trusted-package.h5p
```

Cài thư viện từ nguồn tin cậy trong thời gian bảo trì; gói thư viện chứa mã chạy trên trình duyệt. Kiểm tra tương thích trước khi cập nhật trên production. MVP không thực hiện nâng cấp nội dung giữa major/minor version và không hỗ trợ H5P Content Hub trực tuyến. Gói import của trainer chỉ lấy nội dung; các thư viện đi kèm không được cài.

Thư viện demo Hub.DemoQuestion là bài mẫu nhỏ trong repository để thử editor/player và kết quả. Cài các loại H5P chính thức cần dùng bằng lệnh trên.

## Iframe và cookie

Production cần HTTPS và:
```dotenv
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none
SESSION_PARTITIONED_COOKIE=true
```

State OIDC được gắn với trình duyệt để chống login CSRF. Nếu trình duyệt chặn phiên nhúng, website cần cho mở launch trong cửa sổ/tab mới. MVP chưa triển khai LTI Client Side Storage/postMessage để hỗ trợ mọi trình duyệt chặn third-party cookies. Không đưa session token dài hạn vào URL.

CSP frame-ancestors giới hạn website được nhúng. URL nội dung cần phiên hợp lệ; copy URL player đơn thuần không thay thế launch LTI.

## Kết quả

Kết quả được lưu trong Hub theo launch, danh tính, deployment, resource link và bài H5P. Đây là dữ liệu báo từ trình duyệt H5P, không phải cơ chế chấm thi chống gian lận. Mỗi dòng hiện là một sự kiện kết quả; chưa có chính sách gộp nhiều lượt làm thành điểm chính thức. Tiến trình tiếp tục giữa các phiên chưa được triển khai.

## Kiểm thử

```sh
docker compose exec app php artisan test --compact
node --test tests/js/results.test.cjs
```

Các test bao phủ JWT/state/nonce/replay, login CSRF, vai trò, tenant, phiên, Deep Linking, import/export, đường dẫn ZIP, tệp riêng và lưu kết quả. JavaScript test kiểm tra sự kiện root/child và báo lỗi lưu.

Các tệp test và khóa test dùng thư mục tạm riêng, không dùng kho nội dung thật.

## Production với Dockhand

- Tạo Git stack từ nhánh `main`, đặt **Compose Path** là `compose.production.yaml` ở gốc repo. Để **Context directory** trống để Dockhand lấy toàn bộ thư mục gốc, gồm `docker/php/Dockerfile` và mã nguồn dùng để build PHP và Nginx.
- Nhập `APP_URL`, `APP_KEY` và `DB_PASSWORD` trong **Environment variables** của stack theo `deploy/env.production.example`. Khi chạy từ CLI, lưu các giá trị vào tệp riêng và truyền đường dẫn bằng `--env-file`.
- Tạo network edge và cấu hình reverse proxy HTTPS trỏ vào service web:80.
- `WEB_PORT` đặt cổng HTTP trên máy chủ Docker, mặc định `8080` và chỉ lắng nghe trên `127.0.0.1`. Proxy chạy trực tiếp trên máy chủ có thể trỏ đến `http://127.0.0.1:8080` hoặc cổng bạn chọn. Proxy chạy trong Docker trên network `edge` dùng `web:80`.
- Đặt APP_URL đúng domain Hub, APP_KEY ổn định, DB_PASSWORD riêng.
- Chạy migration và tạo khóa bằng CLI trước khi nhận traffic.
- Production image chạy với user www-data; bảo đảm storage volume có quyền tương ứng.
- Demo và HTTP LTI bị tắt ở production.
- Không public cổng database/PHP-FPM. Dockhand chỉ dành cho quản trị.

Lưu backup database **cùng** toàn bộ storage (nội dung, thư viện, khóa LTI), và giữ APP_KEY trong kho bí mật. Scheduler xóa login hết hạn và launch không có kết quả sau thời gian đệm; launch có kết quả được giữ lại. Chưa có chính sách xóa dữ liệu người học tự động.

Thiết lập domain/TLS, sao lưu ngoài máy chủ và kết nối website thật theo môi trường triển khai.

## Deploy trực tiếp từ GitHub bằng Dockhand

Dùng `compose.production.yaml`, Context directory `.`, nhánh `main` của repo Hub. Không cần setup.sh, không cần tạo network edge trước và không cần source/.env.

Nhập `APP_URL`, `APP_KEY`, `DB_PASSWORD`, `ADMIN_USERNAME`, `ADMIN_PASSWORD` trong Environment variables của Dockhand. APP_KEY là `base64:` cộng base64 của 32 byte ngẫu nhiên, tạo một lần và giữ nguyên. Xem mẫu `deploy/env.production.example`; đánh dấu Secret cho khóa và mật khẩu. Admin ban đầu cần mật khẩu từ 12 đến 255 ký tự.

Bật **Build images on deploy**, tắt **Re-pull images** cho image local rồi Deploy. Dockerfile production tự cài Composer và build CSS/JS. Service `init` tạo thư mục storage, migrate, tạo khóa LTI nếu chưa có và tạo admin nếu chưa có admin. `init` phải kết thúc Exited (0) trước khi app, worker, scheduler chạy. Khóa LTI được tạo dưới quyền www-data; không tạo lại khi update. Admin hiện có không bị đổi mật khẩu bởi biến bootstrap.

Web mặc định bind `127.0.0.1:8080`; dùng reverse proxy HTTPS. Có thể đổi HTTP_BIND và WEB_PORT theo hạ tầng. Nếu proxy ở container hoặc host khác thì cần cấu hình đường truy cập phù hợp.

Update: Commit → Push main → Dockhand đồng bộ và Build/Deploy. Không cần chạy composer/npm hoặc migrate thủ công trong cập nhật thông thường. Nếu cần chạy lại init cùng image, dùng Redeploy/Force recreate. Sao lưu trước migration; không chạy nhiều đợt deploy cùng database. Giữ nguyên APP_KEY, mật khẩu DB, project name và các volume; không xóa volume khi update. Có thể bỏ ADMIN_PASSWORD sau lần tạo admin đầu tiên.

Cấu hình giữ project name `h5p-hub-production` cùng volume `postgres` và `h5p_storage` của bản production cũ. Bản local `h5p-hub` có project/volume khác: muốn chuyển dữ liệu phải backup/restore PostgreSQL và storage, giữ đúng APP_KEY. Repo Git phải chứa source Hub thật với artisan ở gốc; không dùng khung Client với docker/compose.yaml để deploy Hub.
