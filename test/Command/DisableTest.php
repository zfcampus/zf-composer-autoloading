<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZFTest\ComposerAutoloading\Command;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use ZF\ComposerAutoloading\Command;
use ZFTest\ComposerAutoloading\ProjectSetupTrait;

class DisableTest extends TestCase
{
    use ProjectSetupTrait;

    /** @var vfsStreamDirectory */
    private $dir;

    /** @var vfsStreamDirectory */
    private $modulesDir;

    /** @var Command\Disable */
    private $command;

    protected function setUp()
    {
        parent::setUp();

        $this->dir = vfsStream::setup('project');
        $this->modulesDir = vfsStream::newDirectory('my-modules')->at($this->dir);
        $this->command = new Command\Disable($this->dir->url(), 'my-modules', $this->composer);
    }

    public function type()
    {
        return [
            'psr-0' => ['psr-0'],
            'psr-4' => ['psr-4'],
        ];
    }

    /**
     * @dataProvider type
     *
     * @param string $type
     */
    public function testReturnsFalseWithoutChangesBecauseComposerAutoloadingAlreadyDisabled($type)
    {
        $this->setUpModule($this->modulesDir, 'App', $type);
        $composerJson = $this->setUpComposerJson(
            $this->dir,
            ['autoload' => [$type => ['Other\\' => 'path/to/module/src']]]
        );

        $this->assertNotComposerDumpAutoload();
        $this->assertFalse($this->command->process('App', $type));
        $json = json_decode($composerJson->getContent(), true);
        $this->assertCount(1, $json['autoload'][$type]);
        $this->assertEquals('path/to/module/src', $json['autoload'][$type]['Other\\']);
    }

    /**
     * @dataProvider type
     *
     * @param string $type
     */
    public function testRemovesEntryFromComposerJsonAndComposerDumpAutoloadCalled($type)
    {
        $expectedComposerJson = <<< 'EOC'
{
    "autoload": {
        "%s": {
            "Other\\": "path/to/other"
        }
    }
}

EOC;

        $this->setUpModule($this->modulesDir, 'App', $type);
        $composerJson = $this->setUpComposerJson(
            $this->dir,
            ['autoload' => [$type => ['Other\\' => 'path/to/other', 'App\\' => 'my-modules/App/src']]]
        );

        $this->assertComposerDumpAutoload();
        $this->assertTrue($this->command->process('App', $type));

        $composerJsonContent = $composerJson->getContent();
        $json = json_decode($composerJsonContent, true);
        $this->assertCount(1, $json['autoload'][$type]);
        $this->assertEquals('path/to/other', $json['autoload'][$type]['Other\\']);
        $this->assertEquals(sprintf($expectedComposerJson, $type), $composerJsonContent);
    }

    /**
     * @dataProvider type
     *
     * @param string $type
     */
    public function testAddsCorrectEntryToComposerJsonAndComposerDumpAutoloadCalledAutodiscoveryModuleType($type)
    {
        $expectedComposerJson = <<< 'EOC'
{
    "foo": "bar"
}

EOC;

        $this->setUpModule($this->modulesDir, 'MyApp', $type);
        $composerJson = $this->setUpComposerJson(
            $this->dir,
            ['foo' => 'bar', 'autoload' => [$type => ['MyApp\\' => 'my-modules/MyApp/src']]]
        );

        $this->assertComposerDumpAutoload();
        $this->assertTrue($this->command->process('MyApp'));

        $composerJsonContent = $composerJson->getContent();
        $this->assertEquals($expectedComposerJson, $composerJsonContent);
    }
}
