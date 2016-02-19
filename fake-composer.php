#!/usr/bin/env php
<?php
/**
 * 谨以此献给在国内饱受大局域网摧残的同道们
 */

namespace fakecomposer;

use \Exception;

class FakeComposer
{
    /**
     * @return static
     */
    public static function instance(){
        static $instance = null;
        return $instance ? : ($instance = new static());
    }

    public function runHelp(){
        echo <<<HELP
Usage:
    fake-composer command <args> ...

Commands:
    help    -- display this help
    check   -- check dependences
    require [package-name] <package-src-zip>       -- install package from local file
    require [package-name] <package-src-zip-url>   -- install package from online url
HELP;
    }

    public function runCheck()
    {
        $projectBaseDir = getcwd();
        $vendorDir = $projectBaseDir . '/vendor';
        $installedComposerPackagesFile = $vendorDir.'/composer/installed.json';

        $installedPackagesAllData = json_decode(file_get_contents($installedComposerPackagesFile), true);
        $installedPackagesMap = array_column($installedPackagesAllData, null, 'name');
        $dependences = [];
        foreach ($installedPackagesMap as $packageName => $packageInfo) {
            foreach ($packageInfo['require'] as $requirePackage => $requireVersion) {
                $dependences[$requirePackage][$packageName] = $requireVersion;
            }
        }

        foreach ($dependences as $packageName => &$requiring) {
            if (isset($installedPackagesMap[$packageName])){
                $requiring['**installed**'] = $installedPackagesMap[$packageName]['version'];
            } else if ($packageName == 'php'){
                $requiring['**installed**'] = PHP_VERSION;
            } else if (preg_match('/ext-(?<ext>\w+)/', $packageName, $matches)){
                $requiring['**installed**'] = extension_loaded($matches['ext']) ? true : false;
            } else {
                $requiring['**installed**'] = false;
                $requiring['(Project home may be)'] = 'https://github.com/'.$packageName.'/releases';
            }

        }

        echo str_repeat('=', 80). PHP_EOL;
        echo "All dependences:".PHP_EOL;
        echo str_repeat('=', 80). PHP_EOL;
        print_r($dependences);

        // 检查缺失的依赖
        $missingDependences = array_filter($dependences, function($x){
           return !$x['**installed**'];
        });

        if (!empty($missingDependences)){
            echo str_repeat('=', 80). PHP_EOL;
            echo "Warnning: Missing dependences:".PHP_EOL;
            echo str_repeat('=', 80). PHP_EOL;

            print_r($missingDependences);
        } else {
            echo "Great! No missing dependences.".PHP_EOL;
        }

        // 检查冲突的依赖
        $conflictDependences = array_filter(array_combine(array_keys($dependences), array_map(function($x){
            if (!$x['**installed**']){
                return null;
            }

            $installedVersion = $x['**installed**'];
            unset($x['**installed**']);

            foreach ($x as $package => $requiredVersions) {
                if ((new Version($installedVersion))->matches($requiredVersions)) {
                    unset($x[$package]);
                }
            }

            return $x ? $x + ['**installed**' => $installedVersion] : $x;
        }, $dependences)));

        if (!empty($conflictDependences)){
            echo str_repeat('=', 80). PHP_EOL;
            echo "Warnning: Conflict dependences:".PHP_EOL;
            echo str_repeat('=', 80). PHP_EOL;

            print_r($conflictDependences);
        } else {
            echo "Great! No conflict dependences.".PHP_EOL;
        }
    }

    public function runRequire($packageName, $packageSrcZip=null){
        if (!$packageSrcZip){
            $packageSrcZip = $packageName;
            $packageName = null;
        }

        $tmpPackageExtractDir = '/tmp/fake-composer-package-'.date('YmdHis').'-'.md5($packageSrcZip);

        if (preg_match('/^((http(s)?)|(ftp)):\/\//', $packageSrcZip)){
            echo "Info: downloading file from " . $packageSrcZip.PHP_EOL;
            $packageSrcZip = self::downloadFile($packageSrcZip, $tmpPackageExtractDir.'.zip');
        }

        if (!is_file($packageSrcZip)){
            echo "Error: <package-src-zip> not exists!".PHP_EOL;
            return 11;
        }

        echo "Info: extracting file.".PHP_EOL;
        mkdir($tmpPackageExtractDir, 0777, true);
        $unzipCmd = sprintf('unzip "%s" -d "%s"', $packageSrcZip, $tmpPackageExtractDir);
        exec($unzipCmd, $output, $ret);
        if ($ret !== 0){
            echo "Error: failed to unzip!\n Command: " . $unzipCmd . PHP_EOL;
            return 13;
        }

        echo "Info: checking package.".PHP_EOL;
        $extractedDirItems = array_values(array_filter(scandir($tmpPackageExtractDir), function($x){ return $x[0] != '.'; }));
        if (count($extractedDirItems) != 1){
            echo "Error: unknown package!";
            print_r($extractedDirItems);
            return 14;
        }

        $packageNameWithVersion = $extractedDirItems[0];
        if (preg_match('/(?<version>\d+\.\d+(\.\d+)?)/', $packageNameWithVersion, $matches)){
            $packageVersion = $matches['version'];
        } else {
            echo "Error: unknown package version! (From {$packageNameWithVersion})".PHP_EOL;
            return 15;
        }

        echo "Info: got package name with version: {$packageNameWithVersion}.".PHP_EOL;
        echo "Info: got package version: {$packageVersion}.".PHP_EOL;

        $tmpPackageExtractDir .= '/'.$packageNameWithVersion;

        // 解析包属性
        $packageData = json_decode(file_get_contents($tmpPackageExtractDir.'/composer.json'), true);
        $packageData['version'] = $packageVersion;
        if (!empty($packageName) && $packageName != $packageData['name']){
            echo sprintf("Error: package name not match: %s != %s.\n", $packageName, $packageData['name']);
            return 16;
        } else {
            $packageName = $packageData['name'];
        }

        echo "Info: got package name: {$packageName}.".PHP_EOL;

        echo "Info: deploying to vendor directory".PHP_EOL;
        $projectBaseDir = getcwd();
        $vendorDir = $projectBaseDir . '/vendor';
        $composerJsonFile = $projectBaseDir.'/composer.json';
        $composerLockFile = $projectBaseDir.'/composer.lock';

        $composerData = json_decode(file_get_contents($composerJsonFile), true);
        if (!$composerData){
            echo "Error: invalid composer.json!". PHP_EOL;
            return 12;
        }

        $packageDeployDir = $vendorDir . '/' . $packageName;
        exec(sprintf('rm -rf "%s"', $packageDeployDir));
        !is_dir(dirname($packageDeployDir)) and mkdir(dirname($packageDeployDir), 0777, true);
        exec(sprintf('mv "%s" "%s"', $tmpPackageExtractDir, ($packageDeployDir)));

        echo "Info: update composer data files.".PHP_EOL;
        self::updateJsonFile($composerJsonFile, function($data)use($packageVersion, $packageName){
            $data['require'][$packageName] = $packageVersion;
            return $data;
        });

        // 写入composer.lock
        self::updateJsonFile($composerLockFile, function($data) use ($packageData){
            foreach ($data['packages'] as $index => &$package) {
                if ($package['name'] == $packageData['name']){
                    $package = $packageData;
                    return $data;
                }
            }

            $data['packages'][] = $packageData;
            return $data;
        });

        $installedComposerPackagesFile = $vendorDir.'/composer/installed.json';
        self::updateJsonFile($installedComposerPackagesFile, function($data) use ($packageData){
            foreach ($data as $index => &$package) {
                if ($package['name'] == $packageData['name']){
                    $package = $packageData;
                    return $data;
                }
            }

            $data[] = $packageData;
            return $data;
        });

        // 更新自动加载器
        exec('composer dump-autoload');

        echo "Done!". PHP_EOL;
        return 0;
    }

    protected function downloadFile($remoteFileUrl, $localFilePath)
    {
        exec(sprintf('wget "%s" -O "%s"', $remoteFileUrl, $localFilePath), $output, $ret);
        if ($ret != 0){
            throw new Exception("Failed to download file ".$remoteFileUrl.' to '.$localFilePath);
        }

        return $localFilePath;
    }

    protected static function updateJsonFile($file, callable $updater){
        echo "Update ".$file.PHP_EOL;
        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        $data = json_decode(file_get_contents($file), true);
        $data = $updater($data);
        return file_put_contents($file, json_encode($data, $jsonFlags));
    }
}

class Version
{
    protected $version;
    public function __construct($version){
        if (preg_match('/(?<v>\d+(\.\d+)*)/', $version, $matches)){
            $this->version = $matches['v'];
        } else {
            $this->version = '0.0.0';
        }
    }

    /**
     * 检查版本是否匹配某个模式
     * @ref: https://getcomposer.org/doc/articles/versions.md
     * @param $pattern
     * @return bool
     */
    public function matches($pattern){
        $list = explode('|', $pattern);

        foreach ($list as $item) {
            if ($this->_matchesAnd($item)){
                return true;
            }
        }

        return false;
    }

    public function compare($version, $op=null){
        return version_compare($this->version, strval($version), $op);
    }

    protected function _matchesAnd($pattern){
        $list = explode(',', $pattern);

        foreach ($list as $item) {
            if (!$this->_matchesOne($item)){
                return false;
            }
        }

        return true;
    }

    protected function _matchesOne($pattern){
        // *
        if ($pattern == '*'){
            return true;
        }

        // ~1.1 => >=1.1 and <2.0
        if ($pattern[0] == '~'){
            $beginVersion = new Version($pattern);
            $endVersion = $beginVersion->nextSignificantVersion();
            return $this->compare($beginVersion, '>=') and $this->compare($endVersion, '<');
        }

        // ^1.0
        if ($pattern[0] == '^'){
            $beginVersion = new Version($pattern);
            $endVersion = $beginVersion->nextSemanticVersion();
            return $this->compare($beginVersion, '>=') and $this->compare($endVersion, '<');
        }

        // >=1.0
        // <=1.0
        if (preg_match('/^(?<op>[<>=]+)(?<v>\d+(\.\d+)*)/', $pattern, $matches)){
            return $this->compare($matches['v'], $matches['op']);
        }

        // 1.* => /^1\..*(\..*)?$/
        $pattern = str_replace('.', '\\.', $pattern);
        $pattern = str_replace('*', '.*', $pattern);
        $pattern = str_replace('x', '.*', $pattern);
        $pattern = str_replace('?', '.', $pattern);
        return !!preg_match('/^'.$pattern.'(\..*)?$/', $this->version);
    }

    // 1.2 => 2.0
    // 1.2.3 => 1.3.0
    public function nextSignificantVersion(){
        $parts = explode('.', $this->version);
        $parts[count($parts) - 2] += 1;
        $parts[count($parts) - 1] = 0;
        return new Version(implode('.', $parts));
    }

    // 1.2 => 2.0
    // 1.2.3 => 2.0.0
    public function nextSemanticVersion(){
        $parts = explode('.', $this->version);
        $parts = array_pad([$parts[0] + 1], count($parts), 0);
        return new Version(implode('.', $parts));
    }

    public function __toString(){
        return $this->version;
    }
}

global $argv;

call_user_func(function($argv){
    list($exe, $cmd) = $argv;
    $cmd = $cmd ?: 'help';
    $method = "run{$cmd}";
    $ret = call_user_func_array([FakeComposer::instance(), $method], array_slice($argv, 2));
    exit($ret);
}, $argv);




