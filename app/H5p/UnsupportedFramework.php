<?php

namespace App\H5p;

abstract class UnsupportedFramework implements \H5PFrameworkInterface
{
    public function getPlatformInfo()
    {
        throw new \LogicException('H5P operation not enabled: getPlatformInfo');
    }

    public function fetchExternalData($url, $data = null, $blocking = true, $stream = null, $fullData = false, $headers = [
    ], $files = [
    ], $method = 'POST')
    {
        throw new \LogicException('H5P operation not enabled: fetchExternalData');
    }

    public function setLibraryTutorialUrl($machineName, $tutorialUrl)
    {
        throw new \LogicException('H5P operation not enabled: setLibraryTutorialUrl');
    }

    public function setErrorMessage($message, $code = null)
    {
        throw new \LogicException('H5P operation not enabled: setErrorMessage');
    }

    public function setInfoMessage($message)
    {
        throw new \LogicException('H5P operation not enabled: setInfoMessage');
    }

    public function getMessages($type)
    {
        throw new \LogicException('H5P operation not enabled: getMessages');
    }

    public function t($message, $replacements = [
    ])
    {
        throw new \LogicException('H5P operation not enabled: t');
    }

    public function getLibraryFileUrl($libraryFolderName, $fileName)
    {
        throw new \LogicException('H5P operation not enabled: getLibraryFileUrl');
    }

    public function getUploadedH5pFolderPath()
    {
        throw new \LogicException('H5P operation not enabled: getUploadedH5pFolderPath');
    }

    public function getUploadedH5pPath()
    {
        throw new \LogicException('H5P operation not enabled: getUploadedH5pPath');
    }

    public function loadAddons()
    {
        throw new \LogicException('H5P operation not enabled: loadAddons');
    }

    public function getLibraryConfig($libraries = null)
    {
        throw new \LogicException('H5P operation not enabled: getLibraryConfig');
    }

    public function loadLibraries()
    {
        throw new \LogicException('H5P operation not enabled: loadLibraries');
    }

    public function getAdminUrl()
    {
        throw new \LogicException('H5P operation not enabled: getAdminUrl');
    }

    public function getLibraryId($machineName, $majorVersion = null, $minorVersion = null)
    {
        throw new \LogicException('H5P operation not enabled: getLibraryId');
    }

    public function getWhitelist($isLibrary, $defaultContentWhitelist, $defaultLibraryWhitelist)
    {
        throw new \LogicException('H5P operation not enabled: getWhitelist');
    }

    public function isPatchedLibrary($library)
    {
        throw new \LogicException('H5P operation not enabled: isPatchedLibrary');
    }

    public function isInDevMode()
    {
        throw new \LogicException('H5P operation not enabled: isInDevMode');
    }

    public function mayUpdateLibraries()
    {
        throw new \LogicException('H5P operation not enabled: mayUpdateLibraries');
    }

    public function saveLibraryData(&$libraryData, $new = true)
    {
        throw new \LogicException('H5P operation not enabled: saveLibraryData');
    }

    public function insertContent($content, $contentMainId = null)
    {
        throw new \LogicException('H5P operation not enabled: insertContent');
    }

    public function updateContent($content, $contentMainId = null)
    {
        throw new \LogicException('H5P operation not enabled: updateContent');
    }

    public function resetContentUserData($contentId)
    {
        throw new \LogicException('H5P operation not enabled: resetContentUserData');
    }

    public function saveLibraryDependencies($libraryId, $dependencies, $dependency_type)
    {
        throw new \LogicException('H5P operation not enabled: saveLibraryDependencies');
    }

    public function copyLibraryUsage($contentId, $copyFromId, $contentMainId = null)
    {
        throw new \LogicException('H5P operation not enabled: copyLibraryUsage');
    }

    public function deleteContentData($contentId)
    {
        throw new \LogicException('H5P operation not enabled: deleteContentData');
    }

    public function deleteLibraryUsage($contentId)
    {
        throw new \LogicException('H5P operation not enabled: deleteLibraryUsage');
    }

    public function saveLibraryUsage($contentId, $librariesInUse)
    {
        throw new \LogicException('H5P operation not enabled: saveLibraryUsage');
    }

    public function getLibraryUsage($libraryId, $skipContent = false)
    {
        throw new \LogicException('H5P operation not enabled: getLibraryUsage');
    }

    public function loadLibrary($machineName, $majorVersion, $minorVersion)
    {
        throw new \LogicException('H5P operation not enabled: loadLibrary');
    }

    public function loadLibrarySemantics($machineName, $majorVersion, $minorVersion)
    {
        throw new \LogicException('H5P operation not enabled: loadLibrarySemantics');
    }

    public function alterLibrarySemantics(&$semantics, $machineName, $majorVersion, $minorVersion)
    {
        throw new \LogicException('H5P operation not enabled: alterLibrarySemantics');
    }

    public function deleteLibraryDependencies($libraryId)
    {
        throw new \LogicException('H5P operation not enabled: deleteLibraryDependencies');
    }

    public function lockDependencyStorage()
    {
        throw new \LogicException('H5P operation not enabled: lockDependencyStorage');
    }

    public function unlockDependencyStorage()
    {
        throw new \LogicException('H5P operation not enabled: unlockDependencyStorage');
    }

    public function deleteLibrary($library)
    {
        throw new \LogicException('H5P operation not enabled: deleteLibrary');
    }

    public function loadContent($id)
    {
        throw new \LogicException('H5P operation not enabled: loadContent');
    }

    public function loadContentDependencies($id, $type = null)
    {
        throw new \LogicException('H5P operation not enabled: loadContentDependencies');
    }

    public function getOption($name, $default = null)
    {
        throw new \LogicException('H5P operation not enabled: getOption');
    }

    public function setOption($name, $value)
    {
        throw new \LogicException('H5P operation not enabled: setOption');
    }

    public function updateContentFields($id, $fields)
    {
        throw new \LogicException('H5P operation not enabled: updateContentFields');
    }

    public function clearFilteredParameters($library_ids)
    {
        throw new \LogicException('H5P operation not enabled: clearFilteredParameters');
    }

    public function getNumNotFiltered()
    {
        throw new \LogicException('H5P operation not enabled: getNumNotFiltered');
    }

    public function getNumContent($libraryId, $skip = null)
    {
        throw new \LogicException('H5P operation not enabled: getNumContent');
    }

    public function isContentSlugAvailable($slug)
    {
        throw new \LogicException('H5P operation not enabled: isContentSlugAvailable');
    }

    public function getLibraryStats($type)
    {
        throw new \LogicException('H5P operation not enabled: getLibraryStats');
    }

    public function getNumAuthors()
    {
        throw new \LogicException('H5P operation not enabled: getNumAuthors');
    }

    public function saveCachedAssets($key, $libraries)
    {
        throw new \LogicException('H5P operation not enabled: saveCachedAssets');
    }

    public function deleteCachedAssets($library_id)
    {
        throw new \LogicException('H5P operation not enabled: deleteCachedAssets');
    }

    public function getLibraryContentCount()
    {
        throw new \LogicException('H5P operation not enabled: getLibraryContentCount');
    }

    public function afterExportCreated($content, $filename)
    {
        throw new \LogicException('H5P operation not enabled: afterExportCreated');
    }

    public function hasPermission($permission, $id = null)
    {
        throw new \LogicException('H5P operation not enabled: hasPermission');
    }

    public function replaceContentTypeCache($contentTypeCache)
    {
        throw new \LogicException('H5P operation not enabled: replaceContentTypeCache');
    }

    public function libraryHasUpgrade($library)
    {
        throw new \LogicException('H5P operation not enabled: libraryHasUpgrade');
    }

    public function replaceContentHubMetadataCache($metadata, $lang)
    {
        throw new \LogicException('H5P operation not enabled: replaceContentHubMetadataCache');
    }

    public function getContentHubMetadataCache($lang = 'en')
    {
        throw new \LogicException('H5P operation not enabled: getContentHubMetadataCache');
    }

    public function getContentHubMetadataChecked($lang = 'en')
    {
        throw new \LogicException('H5P operation not enabled: getContentHubMetadataChecked');
    }

    public function setContentHubMetadataChecked($time, $lang = 'en')
    {
        throw new \LogicException('H5P operation not enabled: setContentHubMetadataChecked');
    }

    public function resetHubOrganizationData()
    {
        throw new \LogicException('H5P operation not enabled: resetHubOrganizationData');
    }
}
