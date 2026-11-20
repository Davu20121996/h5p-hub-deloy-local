<?php

namespace App\H5p;

use App\Models\H5pLibrary;

class EditorStorage implements \H5PEditorAjaxInterface, \H5peditorStorage
{
    public function getLanguage($machineName, $majorVersion, $minorVersion, $language): ?string
    {
        if (! preg_match('/^[a-zA-Z-]{2,12}$/', $language)) {
            return null;
        }
        $library = (new Framework)->loadLibrary($machineName, $majorVersion, $minorVersion);
        if (! $library) {
            return null;
        }
        $path = Runtime::libraryPath().'/'.\H5PCore::libraryToFolderName($library).'/language/'.$language.'.json';

        return is_file($path) ? file_get_contents($path) : null;
    }

    public function getAvailableLanguages($machineName, $majorVersion, $minorVersion): array
    {
        return ['en'];
    }

    public function keepFile($fileId): void {}

    public function getLibraries($libraries = null): array
    {
        $result = [];
        $query = H5pLibrary::query();
        if ($libraries === null) {
            $query->where('runnable', true);
        }
        foreach ($query->orderBy('machine_name')->orderByDesc('minor_version')->get() as $record) {
            if ($libraries !== null && ! collect($libraries)->contains(fn ($item) => $item->name === $record->machine_name && (int) $item->majorVersion === $record->major_version && (int) $item->minorVersion === $record->minor_version)) {
                continue;
            }
            $library = $record->definition();
            $result[] = (object) [
                'uberName' => \H5PCore::libraryToString($library), 'name' => $record->machine_name,
                'title' => $library['title'], 'majorVersion' => $record->major_version, 'minorVersion' => $record->minor_version,
                'runnable' => $record->runnable, 'restricted' => false, 'tutorialUrl' => $library['tutorialUrl'] ?? null,
                'metadataSettings' => is_string($library['metadataSettings'] ?? null) ? json_decode($library['metadataSettings']) : ($library['metadataSettings'] ?? null),
            ];
        }

        return $result;
    }

    public function alterLibraryFiles(&$files, $libraries): void {}

    public static function saveFileTemporarily($data, $move_file): mixed
    {
        throw new \LogicException('Use the authenticated upload endpoint.');
    }

    public static function markFileForCleanup($file, $content_id): void {}

    public static function removeTemporarilySavedFiles($filePath): void
    {
        throw new \LogicException('Temporary files are cleaned by the scheduler.');
    }

    public function getLatestLibraryVersions(): array
    {
        return $this->getLibraries();
    }

    public function getContentTypeCache($machineName = null): array
    {
        return [];
    }

    public function getAuthorsRecentlyUsedLibraries(): array
    {
        return [];
    }

    public function validateEditorToken($token): bool
    {
        return is_string($token) && hash_equals(csrf_token(), $token);
    }

    public function getTranslations($libraries, $language_code): array
    {
        return [];
    }
}
