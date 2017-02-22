<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\ComposerAutoloading\Command;

use ZF\ComposerAutoloading\Exception;

abstract class AbstractCommand
{
    /**
     * @var string
     */
    protected $projectDir;

    /**
     * @var string
     */
    protected $modulePath;

    /**
     * @var string
     */
    protected $composer;

    /**
     * @var string
     */
    protected $composerJsonFile;

    /**
     * @var array
     */
    protected $composerPackage;

    /**
     * @var string
     */
    protected $moduleName;

    /**
     * @var string
     */
    protected $type;

    /**
     * @param string $projectDir
     * @param string $modulesPath
     * @param string $composer
     */
    public function __construct($projectDir, $modulesPath, $composer)
    {
        $this->projectDir = $projectDir;
        $this->modulesPath = $modulesPath;
        $this->composer = $composer;
    }

    /**
     * @param string $moduleName
     * @param null|string $type
     * @return bool
     * @throws Exception\RuntimeException
     */
    public function process($moduleName, $type = null)
    {
        $this->moduleName = $moduleName;
        $this->modulePath = sprintf('%s/%s/%s', $this->projectDir, $this->modulesPath, $moduleName);
        $this->type = $type ?: $this->autodiscoverModuleType();
        $this->composerPackage = $this->getComposerJson();

        $content = $this->execute();

        if ($content !== false) {
            $this->writeJsonFileAndDumpAutoloader($content);
            return true;
        }

        return false;
    }

    /**
     * Validate that the composer.json exists, is writable, and contains valid contents.
     *
     * @return string
     * @throws Exception\RuntimeException
     */
    public function getComposerJson()
    {
        $this->composerJsonFile = sprintf('%s/composer.json', $this->projectDir);

        if (! is_readable($this->composerJsonFile)) {
            throw new Exception\RuntimeException('composer.json file does not exist or is not readable');
        }
        if (! is_writable($this->composerJsonFile)) {
            throw new Exception\RuntimeException('composer.json file is not writable');
        }

        $composerJson = file_get_contents($this->composerJsonFile);
        $composerPackage = json_decode($composerJson, true);
        if (! is_array($composerPackage)) {
            $error = json_last_error();
            $error = $error === JSON_ERROR_NONE
                ? 'The composer.json file was empty'
                : 'Error parsing composer.json file; please check that it is valid';
            throw new Exception\RuntimeException($error);
        }

        return $composerPackage;
    }

    /**
     * Determine the autoloading type for the module.
     *
     * If passed as a flag, uses that.
     *
     * Otherwise, introspects the module tree to determine if PSR-0 or PSR-4 is
     * being used.
     *
     * If the module tree does not include a src/ directory, returns false,
     * indicating inability to autodiscover.
     *
     * Sets the type property on successful discovery.
     *
     * @return string
     * @throws Exception\RuntimeException
     */
    protected function autodiscoverModuleType()
    {
        $psr0Spec = sprintf('%s/src/%s', $this->modulePath, $this->moduleName);
        if (is_dir($psr0Spec)) {
            return 'psr-0';
        }

        $srcPath = sprintf('%s/src', $this->modulePath);
        if (! is_dir($srcPath)) {
            throw new Exception\RuntimeException(
                'Unable to determine autoloading type; no src directory found in module'
            );
        }

        return 'psr-4';
    }

    /**
     * Do autoloading rules already exist for this module?
     *
     * @return bool
     */
    protected function autoloadingRulesExist()
    {
        if (! isset($this->composerPackage['autoload'][$this->type][$this->moduleName . '\\'])) {
            return false;
        }

        return true;
    }

    /**
     * @param string $content
     * @return void
     */
    protected function writeJsonFileAndDumpAutoloader($content)
    {
        file_put_contents($this->composerJsonFile, json_encode(
            $content,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n");

        $command = sprintf('%s dump-autoload', $this->composer);
        system($command);
    }

    /**
     * @return false|string
     */
    abstract protected function execute();
}
