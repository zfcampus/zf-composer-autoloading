<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZFTest\ComposerAutoloading;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamContainer;
use org\bovigo\vfs\vfsStreamFile;
use phpmock\phpunit\PHPMock;
use ZF\ComposerAutoloading\Command;

trait ProjectSetupTrait
{
    use PHPMock;

    /** @var string */
    private $moduleFileContent = <<< 'EOM'
<?php

namespace %s;

class Module
{
    public function getConfigDir()
    {
        return __DIR__ . '/config/';
    }
}

EOM;

    /** @var string */
    private $composer = 'my-composer.phar';

    /**
     * @param vfsStreamContainer $modulesDir
     * @param string $name
     * @param string $type
     * @return void
     */
    protected function setUpModule(vfsStreamContainer $modulesDir, $name, $type)
    {
        vfsStream::newDirectory(sprintf('%s/src/%s', $name, $type === 'psr-0' ? $name : ''))->at($modulesDir);
    }

    /**
     * @param vfsStreamContainer $dir
     * @param array|null $content
     * @return vfsStreamFile
     */
    protected function setUpComposerJson(vfsStreamContainer $dir, array $content = null)
    {
        return vfsStream::newFile('composer.json')
            ->withContent(json_encode($content))
            ->at($dir);
    }

    /**
     * @param vfsStreamContainer $modulesDir
     * @param string $module
     * @param null|string $content
     * @return vfsStreamFile
     */
    protected function setUpModuleClassFile(vfsStreamContainer $modulesDir, $module, $content = null)
    {
        $content = $content ?: sprintf($this->moduleFileContent, $module);

        return vfsStream::newFile('Module.php')
            ->withContent($content)
            ->at($modulesDir->getChild($module));
    }

    /**
     * @return void
     */
    private function assertComposerDumpAutoload()
    {
        $system = $this->getFunctionMock('ZF\ComposerAutoloading\Command', 'system');
        $system->expects($this->once())->willReturnCallback(function ($command) {
            $this->assertEquals($this->composer . ' dump-autoload', $command);
        });
    }

    /**
     * @return void
     */
    private function assertNotComposerDumpAutoload()
    {
        $system = $this->getFunctionMock('ZF\ComposerAutoloading\Command', 'system');
        $system->expects($this->never());
    }
}
