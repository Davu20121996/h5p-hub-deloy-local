<?php

namespace App\H5p;

use App\Models\H5pContent;
use App\Models\H5pLibrary;
use App\Models\LtiLaunch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class Runtime
{
    public Framework $framework;

    public \H5PCore $core;

    public \H5peditor $editor;

    public function __construct(public LtiLaunch $launch, ?string $filesUrl = null)
    {
        $this->framework = new Framework($launch->deployment->tenant_id, $launch->lti_identity_id);
        $storage = new FileStorage($this->tenantPath(), $this->editorPath());
        $this->core = new \H5PCore($this->framework, $storage, $filesUrl ?? route('h5p.files.base', ['launch' => $launch->id]), 'en', false);
        $adapter = new EditorStorage;
        $this->editor = new \H5peditor($this->core, $adapter, $adapter);
    }

    public static function libraryPath(): string
    {
        return config('lti.storage_directory').'/h5p/libraries';
    }

    public function tenantPath(): string
    {
        return config('lti.storage_directory').'/tenants/'.$this->launch->deployment->tenant_id.'/h5p';
    }

    public function editorPath(): string
    {
        return $this->tenantPath().'/editor/'.$this->launch->id;
    }

    public function save(?H5pContent $content, string $libraryString, array $parameters, array $metadata, string $title, string $status): H5pContent
    {
        abort_unless($this->launch->can_author, 403);
        if ($content) {
            $this->launch->authorizeEdit($content);
        }
        $spec = \H5PCore::libraryFromString($libraryString);
        abort_unless($spec, 422, 'Invalid H5P library.');
        $library = H5pLibrary::where('machine_name', $spec['machineName'])->where('major_version', $spec['majorVersion'])->where('minor_version', $spec['minorVersion'])->where('runnable', true)->firstOrFail();
        $metadata = (new \H5PContentValidator($this->framework, $this->core))->validateMetadata(array_merge($metadata, ['title' => $title]));
        $this->checkDependencies($library->definition());
        self::validatePaths($parameters);
        $content = DB::transaction(function () use ($content, $library, $parameters, $metadata, $title, $status) {
            $values = [
                'h5p_library_id' => $library->id, 'title' => $title, 'status' => $status,
                'parameters' => $parameters, 'metadata' => $metadata, 'filtered' => null, 'dependencies' => null,
            ];
            if (! $content) {
                $content = H5pContent::create($values + ['uuid' => (string) Str::uuid(), 'tenant_id' => $this->launch->deployment->tenant_id, 'lti_identity_id' => $this->launch->lti_identity_id]);
            } else {
                $content->update($values);
            }
            $object = json_decode(json_encode($parameters, JSON_THROW_ON_ERROR), false, 128, JSON_THROW_ON_ERROR);
            $this->editor->processParameters($content->id, $library->definition(), $object);
            $content->update(['parameters' => json_decode(json_encode($object), true)]);
            $this->filter($content->fresh());

            return $content->fresh();
        });

        return $content;
    }

    public function filter(H5pContent $content): string
    {
        $data = ['id' => $content->id, 'title' => $content->title, 'slug' => $content->uuid,
            'library' => $content->library->definition(), 'params' => json_encode((object) $content->parameters, JSON_THROW_ON_ERROR)];
        $filtered = $this->core->filterParameters($data);
        if ($filtered === null || $this->framework->getMessages('error') !== []) {
            throw ValidationException::withMessages(['parameters' => implode(' ', $this->framework->getMessages('error')) ?: 'Invalid H5P parameters.']);
        }

        return $filtered;
    }

    public function import(string $path): H5pContent
    {
        abort_unless($this->launch->can_author, 403);
        $zip = self::openPackage($path);
        $content = null;
        try {
            $manifest = self::jsonEntry($zip, 'h5p.json');
            $parameters = self::jsonEntry($zip, 'content/content.json');
            $dependency = collect($manifest['preloadedDependencies'] ?? [])->firstWhere('machineName', $manifest['mainLibrary'] ?? '');
            abort_unless(is_array($dependency), 422, 'Gói .h5p thiếu thông tin loại bài.');
            self::validatePaths($parameters);
            $library = H5pLibrary::where('machine_name', $dependency['machineName'])->where('major_version', $dependency['majorVersion'] ?? -1)->where('minor_version', $dependency['minorVersion'] ?? -1)->where('runnable', true)->first();
            abort_unless($library !== null, 422, 'Loại bài H5P này chưa được cài. Vui lòng nhờ admin hệ thống cài thư viện trước khi nhập bài.');
            $this->checkDependencies($library->definition());
            self::validatePaths($parameters);
            $content = H5pContent::create([
                'uuid' => (string) Str::uuid(), 'tenant_id' => $this->launch->deployment->tenant_id, 'lti_identity_id' => $this->launch->lti_identity_id,
                'h5p_library_id' => $library->id, 'title' => Str::limit((string) ($manifest['title'] ?? 'Imported H5P'), 255, ''),
                'parameters' => $parameters, 'metadata' => array_intersect_key($manifest, array_flip(['title', 'license', 'authors', 'source', 'licenseVersion'])), 'status' => 'draft',
            ]);
            $root = $this->tenantPath().'/content/'.$content->id;
            File::ensureDirectoryExists($root);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! str_starts_with($name, 'content/') || str_ends_with($name, '/')) {
                    continue;
                }
                $relative = substr($name, 8);
                self::assertMediaPath($relative);
                self::copyEntry($zip, $name, $root.'/'.$relative);
            }
            $this->filter($content);

            return $content->fresh();
        } catch (\Throwable $exception) {
            if ($content) {
                File::deleteDirectory($this->tenantPath().'/content/'.$content->id);
                $content->delete();
            }
            throw $exception;
        } finally {
            $zip->close();
        }
    }

    public function export(H5pContent $content): string
    {
        $this->launch->authorizeEdit($content);
        $this->filter($content);
        $definition = $content->library->definition();
        $dependencies = $this->framework->loadContentDependencies($content->id);
        $manifest = array_merge($content->metadata ?? [], [
            'title' => $content->title, 'language' => 'en', 'mainLibrary' => $definition['machineName'],
            'embedTypes' => ['div'], 'license' => $content->metadata['license'] ?? 'U',
        ]);
        foreach ($dependencies as $dependency) {
            $manifest[$dependency['dependencyType'].'Dependencies'][] = array_intersect_key($dependency, array_flip(['machineName', 'majorVersion', 'minorVersion']));
        }
        $directory = $this->tenantPath().'/exports';
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.Str::uuid().'.h5p';
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::CREATE) === true, 500);
        $zip->addFromString('h5p.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $contentPath = $this->tenantPath().'/content/'.$content->id;
        if (is_dir($contentPath)) {
            foreach (File::allFiles($contentPath) as $file) {
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                if ($relative !== 'content.json') {
                    $zip->addFile($file->getPathname(), 'content/'.$relative);
                }
            }
        }
        $zip->addFromString('content/content.json', $content->fresh()->filtered);
        foreach ($dependencies as $dependency) {
            $folder = \H5PCore::libraryToFolderName($dependency);
            $root = self::libraryPath().'/'.$folder;
            foreach (File::allFiles($root) as $file) {
                $zip->addFile($file->getPathname(), $folder.'/'.str_replace('\\', '/', $file->getRelativePathname()));
            }
        }
        $zip->close();

        return $path;
    }

    public function checkDependencies(array $library, array &$seen = []): void
    {
        $key = \H5PCore::libraryToString($library);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        foreach (['preloadedDependencies', 'dynamicDependencies', 'editorDependencies'] as $field) {
            foreach ($library[$field] ?? [] as $dependency) {
                $definition = $this->framework->loadLibrary($dependency['machineName'], $dependency['majorVersion'], $dependency['minorVersion']);
                abort_unless($definition !== null, 422, 'Missing dependency: '.$dependency['machineName']);
                $this->checkDependencies($definition, $seen);
            }
        }
    }

    public static function validatePaths(array $parameters): void
    {
        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                self::validatePaths($value);
            }
            if ($key === 'path' && is_string($value)) {
                if (preg_match('~^https?://~i', $value)) {
                    continue;
                }
                $path = preg_replace('/#tmp$/', '', $value);
                self::safePath($path);
                self::assertMediaPath($path);
            }
        }
    }

    public static function safePath(string $path): void
    {
        abort_if($path === '' || str_contains($path, '\\') || str_contains($path, ':') || str_contains($path, "\0")
            || str_starts_with($path, '/') || preg_match('~(^|/)[.]{1,2}(/|$)~', $path)
            || preg_match('/[\x00-\x1f]/', $path), 422, 'Unsafe package path.');
    }

    public static function assertMediaPath(string $path): void
    {
        self::safePath($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, explode(' ', \H5PCore::$defaultContentWhitelist), true), 422, 'Unsupported content file type.');
    }

    public static function openPackage(string $path): ZipArchive
    {
        abort_if(filesize($path) > config('lti.max_package_bytes'), 422, 'Package is too large.');
        $zip = new ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'Invalid H5P ZIP.');
        try {
            abort_if($zip->numFiles > 10000, 422, 'Too many package files.');
            $size = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                self::safePath(rtrim($info['name'], '/'));
                $normalized = strtolower($info['name']);
                abort_if(isset($names[$normalized]), 422, 'Duplicate package path.');
                $names[$normalized] = true;
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                abort_if(($attributes >> 16 & 0170000) === 0120000, 422, 'Package symlinks are forbidden.');
                $size += $info['size'];
                abort_if($size > config('lti.max_extracted_bytes'), 422, 'Extracted package is too large.');
            }

            return $zip;
        } catch (\Throwable $exception) {
            $zip->close();
            throw $exception;
        }
    }

    public static function jsonEntry(ZipArchive $zip, string $name): array
    {
        $text = $zip->getFromName($name);
        abort_unless(is_string($text) && strlen($text) <= 5 * 1024 * 1024, 422, 'Missing or oversized '.$name);
        try {
            $result = json_decode($text, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422, 'Invalid JSON in '.$name);
        }
        abort_unless(is_array($result), 422, 'Invalid JSON object.');

        return $result;
    }

    public static function copyEntry(ZipArchive $zip, string $name, string $destination): void
    {
        File::ensureDirectoryExists(dirname($destination));
        $source = $zip->getStream($name);
        abort_unless(is_resource($source), 422);
        $target = fopen($destination, 'wb');
        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }
    }
}
