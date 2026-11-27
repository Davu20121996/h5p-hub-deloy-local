<?php

namespace App\Http\Controllers;

use App\H5p\Runtime;
use App\Models\H5pContent;
use App\Models\H5pLibrary;
use App\Models\LtiLaunch;
use App\Models\LtiResourceLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WorkspaceController extends Controller
{
    public function index(LtiLaunch $launch): View|RedirectResponse
    {
        if ($launch->message_type === 'LtiResourceLinkRequest' && ! $launch->can_author) {
            return redirect()->route('content.play', ['launch' => $launch->id, 'uuid' => $launch->content_uuid]);
        }
        abort_unless($launch->can_author, 403);
        $contents = H5pContent::where('tenant_id', $launch->deployment->tenant_id)
            ->where('admin_hidden', false)
            ->where(fn ($query) => $query->where('status', 'published')->orWhere('lti_identity_id', $launch->lti_identity_id))
            ->orderByDesc('updated_at')->paginate(20);

        return view('workspace.index', compact('launch', 'contents'));
    }

    public function edit(Request $request, LtiLaunch $launch, ?string $uuid = null): View
    {
        abort_unless($launch->can_author, 403);
        $content = $uuid === null ? null : $launch->content($uuid);
        if ($content) {
            $launch->authorizeEdit($content);
        }
        if ($content === null && ! H5pLibrary::where('runnable', true)->exists()) {
            return view('workspace.editor-unavailable', compact('launch'));
        }
        $runtime = new Runtime($launch);
        $validator = new \H5PContentValidator($runtime->framework, $runtime->core);
        $asset = fn ($type, $path) => route('h5p.assets', ['kind' => $type, 'path' => $path]);
        $coreScripts = array_map(fn ($path) => $asset('core', $path), \H5PCore::$scripts);
        $scripts = array_merge($coreScripts, array_map(fn ($path) => $asset('editor', $path), \H5peditor::$scripts), [$asset('editor', 'language/en.js'), $asset('editor', 'scripts/h5peditor-init.js')]);
        $coreStyles = array_map(fn ($path) => $asset('core', $path), \H5PCore::$styles);
        $editorStyles = array_merge($coreStyles, array_map(fn ($path) => $asset('editor', $path), \H5peditor::$styles), [
            asset('h5p-editor-frame.css').'?v='.filemtime(public_path('h5p-editor-frame.css')),
        ]);
        $integration = [
            'baseUrl' => url('/'), 'url' => route('h5p.files.base', ['launch' => $launch->id]),
            'libraryUrl' => $asset('core', ''), 'postUserStatistics' => false, 'saveFreq' => false,
            'hubIsEnabled' => false, 'pluginCacheBuster' => '', 'loadedJs' => [], 'loadedCss' => [],
            'l10n' => ['H5P' => $runtime->core->getLocalization()],
            'editor' => [
                'libraryUrl' => rtrim($asset('editor', ''), '/').'/', 'fileIcon' => $asset('editor', 'images/binary-file.png'),
                'ajaxPath' => route('h5p.ajax', ['launch' => $launch->id]).'?_token='.csrf_token().'&action=',
                'filesPath' => route('h5p.files', ['launch' => $launch->id, 'path' => 'editor']),
                'nodeVersionId' => $content?->id,
                'apiVersion' => \H5PCore::$coreApi, 'language' => 'en', 'enableContentHub' => false,
                'copyrightSemantics' => $validator->getCopyrightSemantics(), 'metadataSemantics' => $validator->getMetadataSemantics(),
                'assets' => ['js' => $scripts, 'css' => $editorStyles],
            ],
        ];
        $libraryString = $content ? \H5PCore::libraryToString($content->library->definition()) : '';
        $parameters = json_encode(['params' => $content ? (object) $content->parameters : new \stdClass, 'metadata' => array_merge($content?->metadata ?? [], ['title' => $content?->title ?? ''])]);
        $previousLibrary = $request->old('library');
        $previousParameters = $request->old('parameters');
        if (is_string($previousLibrary) && is_string($previousParameters) && preg_match('/\A[A-Za-z0-9_.-]+ [0-9]+\.[0-9]+\z/', $previousLibrary)) {
            $libraryString = $previousLibrary;
            $parameters = $previousParameters;
        }

        return view('workspace.editor', compact('launch', 'content', 'integration', 'scripts', 'coreStyles', 'libraryString', 'parameters'));
    }

    public function save(Request $request, LtiLaunch $launch, ?string $uuid = null): RedirectResponse
    {
        abort_unless($launch->can_author, 403);
        $input = $request->validate([
            'title' => $this->plainTextRules(), 'library' => 'required|string|max:255',
            'parameters' => 'required|string|max:2097152|json', 'status' => 'required|in:draft,published',
        ]);
        try {
            $data = json_decode($input['parameters'], true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422, 'Invalid parameters.');
        }
        abort_unless(is_array($data['params'] ?? null) && is_array($data['metadata'] ?? []), 422);
        $runtime = new Runtime($launch);
        $content = $runtime->save($uuid ? $launch->content($uuid) : null, $input['library'], $data['params'], $data['metadata'] ?? [], $input['title'], $input['status']);

        return redirect()->route('content.edit', ['launch' => $launch->id, 'uuid' => $content->uuid])->with('status', 'Đã lưu bài H5P.');
    }

    public function import(Request $request, LtiLaunch $launch): RedirectResponse
    {
        abort_unless($launch->can_author, 403);
        $request->validate(['package' => 'required|file|max:51200|extensions:h5p', 'publish' => 'sometimes|boolean']);
        try {
            $content = (new Runtime($launch))->import($request->file('package')->getRealPath());
        } catch (\JsonException) {
            throw ValidationException::withMessages(['package' => 'Dữ liệu JSON trong gói H5P không hợp lệ.']);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 422) {
                throw $exception;
            }

            throw ValidationException::withMessages(['package' => $exception->getMessage() ?: 'Gói H5P không hợp lệ.']);
        }

        if ($request->boolean('publish')) {
            $content->update(['status' => 'published']);
        }

        return redirect()->route('workspace.index', $launch)->with('status', 'Đã nhập bài H5P. Bạn có thể xem trước hoặc xuất bản bài trong danh sách.');
    }

    public function status(Request $request, LtiLaunch $launch, string $uuid): RedirectResponse
    {
        $content = $launch->content($uuid);
        $launch->authorizeEdit($content);
        $content->update($request->validate(['status' => 'required|in:draft,published']));

        return back()->with('status', 'Đã cập nhật trạng thái bài.');
    }

    public function export(LtiLaunch $launch, string $uuid): BinaryFileResponse
    {
        $content = $launch->content($uuid);

        return response()->download((new Runtime($launch))->export($content), $content->uuid.'.h5p')->deleteFileAfterSend(true);
    }

    public function duplicate(LtiLaunch $launch, string $uuid): RedirectResponse
    {
        abort_unless($launch->can_author, 403);
        $source = $launch->content($uuid);
        abort_unless($source->status === 'published' || $source->lti_identity_id === $launch->lti_identity_id, 403);
        $copy = $source->replicate(['uuid', 'filtered', 'dependencies']);
        $copy->uuid = (string) Str::uuid();
        $copy->lti_identity_id = $launch->lti_identity_id;
        $copy->title = Str::limit($source->title.' (bản sao)', 255, '');
        $copy->status = 'draft';
        $copy->save();
        $runtime = new Runtime($launch);
        $runtime->core->fs->cloneContent($source->id, $copy->id);

        return redirect()->route('workspace.index', $launch)->with('status', 'Đã nhân bản bài H5P.');
    }

    public function destroy(LtiLaunch $launch, string $uuid): RedirectResponse
    {
        $content = $launch->content($uuid);
        $launch->authorizeEdit($content);
        abort_if(LtiResourceLink::where('h5p_content_id', $content->id)->exists(), 409, 'Bài đã được sử dụng. Chuyển về bản nháp để ngừng xuất bản.');
        (new Runtime($launch))->core->fs->deleteContent(['id' => $content->id]);
        $content->delete();

        return redirect()->route('workspace.index', ['launch' => $launch->id]);
    }
}
