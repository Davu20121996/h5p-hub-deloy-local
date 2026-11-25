<?php

namespace App\Http\Controllers;

use App\H5p\Runtime;
use App\Models\H5pContent;
use App\Models\LtiLaunch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class H5pController extends Controller
{
    private function authorizeRead(LtiLaunch $launch, string $uuid): H5pContent
    {
        $content = $launch->content($uuid);
        if (! $launch->can_author) {
            abort_unless($launch->content_uuid === $uuid && $content->status === 'published', 403);
        } else {
            abort_unless($content->status === 'published' || $content->lti_identity_id === $launch->lti_identity_id || $launch->canManageTenant(), 403);
        }

        return $content;
    }

    public function play(LtiLaunch $launch, string $uuid): View
    {
        $content = $this->authorizeRead($launch, $uuid);
        $runtime = new Runtime($launch);
        $filtered = $content->filtered ?? $runtime->filter($content);
        $content->refresh();
        $dependencies = $runtime->framework->loadContentDependencies($content->id, 'preloaded');
        $assets = $runtime->core->getDependenciesFiles($dependencies);
        $coreScripts = array_map(fn ($path) => route('h5p.assets', ['kind' => 'core', 'path' => $path]), \H5PCore::$scripts);
        $coreStyles = array_map(fn ($path) => route('h5p.assets', ['kind' => 'core', 'path' => $path]), \H5PCore::$styles);
        $scripts = $runtime->core->getAssetsUrls($assets['scripts']);
        $styles = $runtime->core->getAssetsUrls($assets['styles']);
        $integration = [
            'baseUrl' => url('/'), 'siteUrl' => url('/'), 'url' => route('h5p.files.base', ['launch' => $launch->id]),
            'urlLibraries' => route('h5p.files', ['launch' => $launch->id, 'path' => 'libraries']),
            'libraryUrl' => route('h5p.assets', ['kind' => 'core', 'path' => '']),
            'postUserStatistics' => false, 'saveFreq' => false, 'loadedJs' => [], 'loadedCss' => [],
            'core' => ['scripts' => $coreScripts, 'styles' => $coreStyles],
            'l10n' => ['H5P' => $runtime->core->getLocalization()],
            'contents' => ['cid-'.$content->id => [
                'library' => \H5PCore::libraryToString($content->library->definition()),
                'url' => route('content.play', ['launch' => $launch->id, 'uuid' => $content->uuid]),
                'jsonContent' => $filtered, 'fullScreen' => true, 'scripts' => $scripts, 'styles' => $styles,
                'contentUrl' => route('h5p.files', ['launch' => $launch->id, 'path' => 'content/'.$content->id]),
                'metadata' => array_merge($content->metadata ?? [], ['title' => $content->title]),
                'displayOptions' => ['frame' => false, 'export' => false, 'embed' => false, 'copyright' => true, 'icon' => false, 'copy' => false],
            ]],
        ];

        return view('workspace.player', compact('launch', 'content', 'integration', 'coreScripts', 'coreStyles', 'scripts', 'styles'));
    }

    public function ajax(Request $request, LtiLaunch $launch, string $action = ''): JsonResponse
    {
        abort_unless($launch->can_author, 403);
        $action = $request->input('action', $action);
        $runtime = new Runtime($launch);
        if ($action === 'libraries') {
            if ($request->filled('machineName')) {
                $input = $request->validate(['machineName' => 'required|regex:/^[A-Za-z0-9_.-]+$/', 'majorVersion' => 'required|integer|min:0', 'minorVersion' => 'required|integer|min:0', 'language' => 'sometimes|regex:/^[a-zA-Z-]{2,12}$/']);
                $library = $runtime->framework->loadLibrary($input['machineName'], (int) $input['majorVersion'], (int) $input['minorVersion']);
                abort_unless($library, 404);

                return response()->json($runtime->editor->getLibraryData($input['machineName'], (int) $input['majorVersion'], (int) $input['minorVersion'], $input['language'] ?? 'en'));
            }
            $_POST['libraries'] = $request->input('libraries');
            if ($_POST['libraries'] === null) {
                unset($_POST['libraries']);
            }

            return response()->json($runtime->editor->getLibraries());
        }
        if ($action === 'files' && $request->isMethod('post')) {
            $request->validate(['file' => 'required|file|max:51200', 'field' => 'required|json']);
            $field = json_decode($request->input('field'), true);
            $type = $field['type'] ?? null;
            $allowed = [
                'image' => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'],
                'audio' => ['audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/mp4' => 'm4a'],
                'video' => ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogg'],
                'file' => ['application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/vtt' => 'vtt'],
            ];
            $file = $request->file('file');
            $mime = $file->getMimeType();
            $extension = $allowed[$type][$mime] ?? null;
            abort_unless($extension !== null, 422, 'Unsupported media type.');
            if ($type === 'image') {
                abort_unless(@getimagesize($file->getRealPath()) !== false, 422);
            }
            $relative = $type.'s/'.Str::uuid().'.'.$extension;
            File::ensureDirectoryExists(dirname($runtime->editorPath().'/'.$relative));
            File::copy($file->getRealPath(), $runtime->editorPath().'/'.$relative);
            $result = ['path' => $relative.'#tmp', 'mime' => $mime];
            if ($type === 'image') {
                [$result['width'],$result['height']] = getimagesize($file->getRealPath());
            }

            return response()->json($result);
        }
        abort(404, 'Unsupported editor operation.');
    }

    public function files(LtiLaunch $launch, string $path = ''): BinaryFileResponse
    {
        Runtime::safePath($path);
        $runtime = new Runtime($launch);
        if (str_starts_with($path, 'libraries/')) {
            return $this->serve(Runtime::libraryPath(), substr($path, 10), true);
        }
        if (str_starts_with($path, 'editor/')) {
            abort_unless($launch->can_author, 403);

            return $this->serve($runtime->editorPath(), substr($path, 7), false);
        }
        abort_unless(preg_match('~^content/([0-9]+)/(.+)$~', $path, $matches), 404);
        $content = H5pContent::where('tenant_id', $launch->deployment->tenant_id)->findOrFail($matches[1]);
        $this->authorizeRead($launch, $content->uuid);

        return $this->serve($runtime->tenantPath().'/content/'.$content->id, $matches[2], false);
    }

    public function assets(string $kind, string $path = ''): BinaryFileResponse
    {
        abort_unless(in_array($kind, ['core', 'editor', 'libraries'], true), 404);
        if ($kind === 'libraries') {
            return $this->serve(Runtime::libraryPath(), $path, true);
        }

        return $this->serve(base_path('vendor/h5p/h5p-'.($kind === 'core' ? 'core' : 'editor')), $path, true);
    }

    private function serve(string $root, string $path, bool $code): BinaryFileResponse
    {
        Runtime::safePath($path);
        $resolvedRoot = realpath($root);
        $resolved = realpath($root.'/'.$path);
        abort_unless($resolvedRoot && $resolved && is_file($resolved) && str_starts_with($resolved, $resolvedRoot.DIRECTORY_SEPARATOR), 404);
        $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        if ($code) {
            abort_unless(in_array($ext, ['js', 'css', 'json', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'html', 'ico', 'map'], true), 404);
        } else {
            Runtime::assertMediaPath($path);
        }
        $types = ['js' => 'application/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'vtt' => 'text/vtt'];

        return response()->file($resolved, [
            'Content-Type' => $types[$ext] ?? (mime_content_type($resolved) ?: 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $code ? 'private, max-age=300' : 'private, no-store',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }

}
