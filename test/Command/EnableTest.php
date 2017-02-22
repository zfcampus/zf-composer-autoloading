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

class EnableTest extends TestCase
{
    use ProjectSetupTrait;

    /** @var vfsStreamDirectory */
    private $dir;

    /** @var vfsStreamDirectory */
    private $modulesDir;

    /** @var Command\Enable */
    private $command;

    protected function setUp()
    {
        parent::setUp();

        $this->dir = vfsStream::setup('project');
        $this->modulesDir = vfsStream::newDirectory('my-modules')->at($this->dir);
        $this->command = new Command\Enable($this->dir->url(), 'my-modules', $this->composer);
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
    public function testReturnsFalseWithoutChangesBecauseComposerAutoloadingAlreadyEnabled($type)
    {
        $this->setUpModule($this->modulesDir, 'App', $type);
        $composerJson = $this->setUpComposerJson(
            $this->dir,
            ['autoload' => [$type => ['App\\' => 'path/to/module/src']]]
        );

        $this->assertNotComposerDumpAutoload();
        $this->assertFalse($this->command->process('App', $type));
        $json = json_decode($composerJson->getContent(), true);
        $this->assertCount(1, $json['autoload'][$type]);
        $this->assertEquals('path/to/module/src', $json['autoload'][$type]['App\\']);
    }

    /**
     * @dataProvider type
     *
     * @param string $type
     */
    public function testAddsEntryToComposerJsonAndComposerDumpAutoloadCalled($type)
    {
        $expectedComposerJson = <<< 'EOC'
{
    "autoload": {
        "%s": {
            "Other\\": "path/to/other",
            "App\\": "my-modules/App/src/"
        }
    }
}

EOC;

        $this->setUpModule($this->modulesDir, 'App', $type);
        $composerJson = $this->setUpComposerJson(
            $this->dir,
            ['autoload' => [$type => ['Other\\' => 'path/to/other']]]
        );

        $this->assertComposerDumpAutoload();
        $this->assertTrue($this->command->process('App', $type));

        $composerJsonContent = $composerJson->getContent();
        $json = json_decode($composerJsonContent, true);
        $this->assertCount(2, $json['autoload'][$type]);
        $this->assertEquals('path/to/other', $json['autoload'][$type]['Other\\']);
        $this->assertEquals('my-modules/App/src/', $json['autoload'][$type]['App\\']);
        $this->assertEquals(sprintf($expectedComposerJson, $type), $composerJsonContent);
        $this->assertNull($this->command->getMovedModuleClass());
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
    "autoload": {
        "%s": {
            "MyApp\\": "my-modules/MyApp/src/"
        }
    }
}

EOC;

        $this->setUpModule($this->modulesDir, 'MyApp', $type);
        $composerJson = $this->setUpComposerJson($this->dir, []);

        $this->assertComposerDumpAutoload();
        $this->assertTrue($this->command->process('MyApp'));

        $composerJsonContent = $composerJson->getContent();
        $json = json_decode($composerJsonContent, true);
        $this->assertCount(1, $json['autoload'][$type]);
        $this->assertEquals('my-modules/MyApp/src/', $json['autoload'][$type]['MyApp\\']);
        $this->assertEquals(sprintf($expectedComposerJson, $type), $composerJsonContent);
        $this->assertNull($this->command->getMovedModuleClass());
    }

    /**
     * @dataProvider type
     *
     * @param string $type
     */
    public function testModuleClassFileDoesNotContainModuleClassSoItIsNotMoved($type)
    {
        $this->setUpModule($this->modulesDir, 'FooApp', $type);
        $this->setUpComposerJson($this->dir, []);
        $this->setUpModuleClassFile($this->modulesDir, 'FooApp', 'require __DIR__ . "/src/Module.php";');

        $this->assertComposerDumpAutoload();
        $this->assertTrue($this->command->process('FooApp'));
        $this->assertNull($this->command->getMovedModuleClass());
    }

    public function moveModuleClassFile()
    {
        return [
            'psr-0-move'     => ['psr-0', true,  ['%s/%s/Module.php' => '%s/%s/src/Module.php']],
            'psr-0-not-move' => ['psr-0', false, null],
            'psr-4-move'     => ['psr-4', true,  ['%s/%s/Module.php' => '%s/%s/src/Module.php']],
            'psr-4-not-move' => ['psr-4', false, null],
        ];
    }

    /**
     * @dataProvider moveModuleClassFile
     *
     * @param string $type
     * @param bool $move
     */
    public function testModuleClassFileExistsInBothLocationSoItIsNotMoved($type, $move)
    {
        $this->setUpModule($this->modulesDir, 'BarApp', $type);
        $this->setUpComposerJson($this->dir, []);
        $moduleFile = $this->setUpModuleClassFile($this->modulesDir, 'BarApp');
        $newModuleFile = vfsStream::newFile('Module.php')
            ->withContent('foo bar content')
            ->at($this->modulesDir->getChild('BarApp')->getChild('src'));

        $this->command->setMoveModuleClass($move);

        $this->assertComposerDumpAutoload();
        $this->assertTrue($this->command->process('BarApp'));
        $this->assertNull($this->command->getMovedModuleClass());
        $this->assertFileExists($moduleFile->url());
        $this->assertFileExists($newModuleFile->url());
        $this->assertEquals(sprintf($this->moduleFileContent, 'BarApp'), $moduleFile->getContent());
        $this->assertEquals('foo bar content', $newModuleFile->getContent());
    }

    /**
     * @dataProvider moveModuleClassFile
     *
     * @param string $type
     * @param bool $move
     * @param null|array $expected
     */
    public function testMovesModuleClassFile($type, $move, $expected)
    {
        $expectedModuleFileContent = <<< 'EOM'
<?php

namespace %s;

class Module
{
    public function getConfigDir()
    {
        return __DIR__ . '/../config/';
    }
}

EOM;

        $this->setUpModule($this->modulesDir, 'FooApp', $type);
        $this->setUpComposerJson($this->dir, []);
        $moduleFile = $this->setUpModuleClassFile($this->modulesDir, 'FooApp');
        $this->command->setMoveModuleClass($move);

        $this->assertComposerDumpAutoload();
        $this->assertTrue($this->command->process('FooApp'));
        if (null === $expected) {
            $this->assertNull($this->command->getMovedModuleClass());
            $this->assertFileExists($moduleFile->url());
        } else {
            $from = sprintf(key($expected), $this->modulesDir->url(), 'FooApp');
            $to = sprintf(reset($expected), $this->modulesDir->url(), 'FooApp');
            $this->assertEquals([$from => $to], $this->command->getMovedModuleClass());
            $this->assertFileNotExists($moduleFile->url());
            $newModuleFile = vfsStream::url('project/my-modules/FooApp/src/Module.php');
            $this->assertFileExists($newModuleFile);
            $this->assertEquals(
                sprintf($expectedModuleFileContent, 'FooApp'),
                file_get_contents($newModuleFile)
            );
        }
    }
}
