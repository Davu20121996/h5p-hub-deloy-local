<?php

namespace App\H5p;

use App\Models\H5pLibrary;
use Illuminate\Support\Facades\File;

class FileStorage extends \H5PDefaultStorage
{
    public function __construct(string $tenantPath, ?string $editorPath = null)
    {
        File::ensureDirectoryExists($tenantPath);
        parent::__construct($tenantPath, $editorPath);
    }

    public function hasPresave($libraryFolder, $developmentPath = null): bool
    {
        return is_file(Runtime::libraryPath().'/'.$libraryFolder.'/presave.js');
    }

    public function getUpgradeScript($machineName, $majorVersion, $minorVersion): ?string
    {
        $library = H5pLibrary::where('machine_name', $machineName)->where('major_version', $majorVersion)->where('minor_version', $minorVersion)->first();
        $path = $library ? '/libraries/'.\H5PCore::libraryToFolderName($library->definition()).'/upgrades.js' : null;

        return $path && is_file(dirname(Runtime::libraryPath()).$path) ? $path : null;
    }

    public function exportLibrary($library, $target, $developmentPath = null): void
    {
        $source = Runtime::libraryPath().'/'.\H5PCore::libraryToFolderName($library);
        $library['patchVersionInFolderName'] = false;
        File::copyDirectory($source, $target.'/'.\H5PCore::libraryToFolderName($library));
    }
}
