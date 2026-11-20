<?php

namespace App\H5p;

use App\Models\H5pContent;
use App\Models\H5pLibrary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class LibraryInstaller
{
    public function install(string $path): int
    {
        return Cache::lock('h5p:library-install', 300)->block(10, function () use ($path) {
            $zip = Runtime::openPackage($path);
            try {
                $libraries = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (! preg_match('~^([^/]+)/library[.]json$~', $name, $matches)) {
                        continue;
                    }
                    $data = Runtime::jsonEntry($zip, $name);
                    abort_unless(preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,150}$/', $data['machineName'] ?? '')
                        && is_int($data['majorVersion'] ?? null) && $data['majorVersion'] >= 0
                        && is_int($data['minorVersion'] ?? null) && $data['minorVersion'] >= 0
                        && is_int($data['patchVersion'] ?? null) && $data['patchVersion'] >= 0
                        && is_string($data['title'] ?? null) && strlen($data['title']) <= 255, 422, 'Invalid library manifest.');
                    $folder = \H5PCore::libraryToFolderName($data);
                    abort_unless($matches[1] === $folder, 422, 'Library folder does not match its manifest.');
                    $semanticsName = $folder.'/semantics.json';
                    $data['semantics'] = $zip->locateName($semanticsName) !== false ? json_encode(Runtime::jsonEntry($zip, $semanticsName), JSON_THROW_ON_ERROR) : '[]';
                    foreach (['preloadedJs', 'preloadedCss'] as $type) {
                        $data[$type] ??= [];
                        foreach ($data[$type] as $asset) {
                            Runtime::safePath($asset['path']);
                            abort_unless($zip->locateName($folder.'/'.$asset['path']) !== false, 422, 'Missing library asset.');
                        }
                    }
                    $data['path'] = 'libraries/'.$folder;
                    $data['embedTypes'] ??= ['div'];
                    $data['fullscreen'] ??= 0;
                    $data['runnable'] ??= 0;
                    $libraries[\H5PCore::libraryToString($data)] = ['folder' => $folder, 'data' => $data];
                }
                abort_unless(count($libraries) > 0, 422, 'No H5P libraries found.');
                foreach ($libraries as $item) {
                    foreach (['preloadedDependencies', 'dynamicDependencies', 'editorDependencies'] as $type) {
                        foreach ($item['data'][$type] ?? [] as $dependency) {
                            $key = \H5PCore::libraryToString($dependency);
                            abort_unless(isset($libraries[$key]) || (new Framework)->getLibraryId($dependency['machineName'], $dependency['majorVersion'], $dependency['minorVersion']), 422, 'Missing library dependency: '.$key);
                        }
                    }
                }
                $installed = 0;
                foreach ($libraries as $item) {
                    $data = $item['data'];
                    $record = H5pLibrary::where('machine_name', $data['machineName'])->where('major_version', $data['majorVersion'])->where('minor_version', $data['minorVersion'])->first();
                    if ($record && $record->patch_version >= $data['patchVersion']) {
                        continue;
                    }
                    $root = Runtime::libraryPath().'/'.$item['folder'];
                    File::ensureDirectoryExists($root);
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if (! str_starts_with($name, $item['folder'].'/') || str_ends_with($name, '/')) {
                            continue;
                        }
                        $relative = substr($name, strlen($item['folder']) + 1);
                        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                        abort_unless(in_array($extension, explode(' ', \H5PCore::$defaultContentWhitelist.' '.\H5PCore::$defaultLibraryWhitelistExtras), true), 422, 'Unsupported library file.');
                        Runtime::copyEntry($zip, $name, $root.'/'.$relative);
                    }
                    H5pLibrary::updateOrCreate(['machine_name' => $data['machineName'], 'major_version' => $data['majorVersion'], 'minor_version' => $data['minorVersion']], [
                        'patch_version' => $data['patchVersion'], 'runnable' => (bool) $data['runnable'], 'data' => $data,
                    ]);
                    $installed++;
                }
                if ($installed > 0) {
                    H5pContent::query()->update(['filtered' => null, 'dependencies' => null]);
                }

                return $installed;
            } finally {
                $zip->close();
            }
        });
    }
}
