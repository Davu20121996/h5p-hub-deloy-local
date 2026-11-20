<?php

namespace App\H5p;

use App\Models\H5pContent;
use App\Models\H5pLibrary;

class Framework extends UnsupportedFramework
{
    public array $messages = [];

    public function __construct(public ?int $tenantId = null, public ?int $identityId = null) {}

    public function getPlatformInfo(): array
    {
        return ['name' => 'Laravel H5P Hub', 'version' => app()->version(), 'h5pVersion' => '1.28'];
    }

    public function setErrorMessage($message, $code = null): void
    {
        $this->messages['error'][] = $message;
    }

    public function setInfoMessage($message): void
    {
        $this->messages['info'][] = $message;
    }

    public function getMessages($type): array
    {
        return $this->messages[$type] ?? [];
    }

    public function t($message, $replacements = []): string
    {
        return strtr($message, $replacements);
    }

    public function loadAddons(): array
    {
        return [];
    }

    public function getLibraryConfig($libraries = null): array
    {
        return [];
    }

    public function getOption($name, $default = null): mixed
    {
        return $default;
    }

    public function setOption($name, $value): void {}

    public function hasPermission($permission, $id = null): bool
    {
        return false;
    }

    public function isInDevMode(): bool
    {
        return false;
    }

    public function mayUpdateLibraries(): bool
    {
        return false;
    }

    public function getAdminUrl(): string
    {
        return '/';
    }

    public function getWhitelist($isLibrary, $defaultContentWhitelist, $defaultLibraryWhitelist): string
    {
        return $defaultContentWhitelist.($isLibrary ? ' '.$defaultLibraryWhitelist : '');
    }

    public function getLibraryId($machineName, $majorVersion = null, $minorVersion = null): mixed
    {
        return H5pLibrary::where('machine_name', $machineName)
            ->when($majorVersion !== null, fn ($query) => $query->where('major_version', $majorVersion))
            ->when($minorVersion !== null, fn ($query) => $query->where('minor_version', $minorVersion))
            ->orderByDesc('major_version')->orderByDesc('minor_version')->value('id');
    }

    public function loadLibrary($machineName, $majorVersion, $minorVersion): ?array
    {
        $library = H5pLibrary::where('machine_name', $machineName)->where('major_version', $majorVersion)->where('minor_version', $minorVersion)->first();

        return $library?->definition();
    }

    public function loadLibrarySemantics($machineName, $majorVersion, $minorVersion): ?string
    {
        $library = $this->loadLibrary($machineName, $majorVersion, $minorVersion);

        return $library['semantics'] ?? null;
    }

    public function alterLibrarySemantics(&$semantics, $machineName, $majorVersion, $minorVersion): void {}

    public function saveLibraryUsage($contentId, $librariesInUse): void
    {
        $this->content($contentId)->update(['dependencies' => $librariesInUse]);
    }

    public function deleteLibraryUsage($contentId): void
    {
        $this->content($contentId)->update(['dependencies' => null]);
    }

    public function loadContentDependencies($id, $type = null): array
    {
        $items = $this->content($id)->dependencies ?? [];
        uasort($items, fn ($a, $b) => $a['weight'] <=> $b['weight']);
        $result = [];
        foreach ($items as $item) {
            if ($type !== null && $item['type'] !== $type) {
                continue;
            }
            $library = $item['library'];
            $library['dependencyType'] = $item['type'];
            $library['path'] = 'libraries/'.\H5PCore::libraryToFolderName($library);
            $result[] = $library;
        }

        return $result;
    }

    public function updateContentFields($id, $fields): void
    {
        $this->content($id)->update(array_intersect_key($fields, array_flip(['filtered'])));
    }

    public function isContentSlugAvailable($slug): bool
    {
        return true;
    }

    public function getLibraryFileUrl($libraryFolderName, $fileName): string
    {
        return '/h5p-assets/libraries/'.$libraryFolderName.'/'.$fileName;
    }

    public function loadLibraries(): array
    {
        $result = [];
        foreach (H5pLibrary::all() as $library) {
            $result[$library->machine_name][] = (object) ['id' => $library->id, 'major_version' => $library->major_version, 'minor_version' => $library->minor_version, 'patch_version' => $library->patch_version];
        }

        return $result;
    }

    public function isPatchedLibrary($library): bool
    {
        $installed = $this->loadLibrary($library['machineName'], $library['majorVersion'], $library['minorVersion']);

        return $installed !== null && $installed['patchVersion'] >= $library['patchVersion'];
    }

    private function content(int $id): H5pContent
    {
        return H5pContent::where('tenant_id', $this->tenantId)->findOrFail($id);
    }
}
