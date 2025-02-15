<?php

class FileCache implements CacheInterface
{
    private array $config;
    protected $scope;
    protected $key;

    public function __construct(array $config = [])
    {
        $this->config = $config;

        if (!is_dir($this->config['path'])) {
            throw new \Exception('The cache path does not exists. You probably want: mkdir cache && chown www-data:www-data cache');
        }
        if (!is_writable($this->config['path'])) {
            throw new \Exception('The cache path is not writeable. You probably want: chown www-data:www-data cache');
        }
    }

    public function loadData()
    {
        if (file_exists($this->getCacheFile())) {
            return unserialize(file_get_contents($this->getCacheFile()));
        }
        return null;
    }

    public function saveData($data)
    {
        $writeStream = file_put_contents($this->getCacheFile(), serialize($data));
        if ($writeStream === false) {
            throw new \Exception('The cache path is not writeable. You probably want: chown www-data:www-data cache');
        }
        return $this;
    }

    public function getTime()
    {
        $cacheFile = $this->getCacheFile();
        clearstatcache(false, $cacheFile);
        if (file_exists($cacheFile)) {
            $time = filemtime($cacheFile);
            if ($time !== false) {
                return $time;
            }
            return null;
        }

        return null;
    }

    public function purgeCache($seconds)
    {
        if (! $this->config['enable_purge']) {
            return;
        }

        $cachePath = $this->getScope();
        if (!file_exists($cachePath)) {
            return;
        }
        $cacheIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cachePath),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($cacheIterator as $cacheFile) {
            if (in_array($cacheFile->getBasename(), ['.', '..', '.gitkeep'])) {
                continue;
            } elseif ($cacheFile->isFile()) {
                if (filemtime($cacheFile->getPathname()) < time() - $seconds) {
                    // todo: sometimes this file doesn't exists
                    unlink($cacheFile->getPathname());
                }
            }
        }
    }

    public function setScope($scope)
    {
        if (!is_string($scope)) {
            throw new \Exception('The given scope is invalid!');
        }

        $this->scope = $this->config['path'] . trim($scope, " \t\n\r\0\x0B\\\/") . '/';

        return $this;
    }

    public function setKey($key)
    {
        $key = json_encode($key);

        if (!is_string($key)) {
            throw new \Exception('The given key is invalid!');
        }

        $this->key = $key;
        return $this;
    }

    private function getScope()
    {
        if (is_null($this->scope)) {
            throw new \Exception('Call "setScope" first!');
        }

        if (!is_dir($this->scope)) {
            if (mkdir($this->scope, 0755, true) !== true) {
                throw new \Exception('mkdir: Unable to create file cache folder');
            }
        }

        return $this->scope;
    }

    private function getCacheFile()
    {
        return $this->getScope() . $this->getCacheName();
    }

    private function getCacheName()
    {
        if (is_null($this->key)) {
            throw new \Exception('Call "setKey" first!');
        }

        return hash('md5', $this->key) . '.cache';
    }
}
