<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZFTest\ComposerAutoloading;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamContainer;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Zend\Stdlib\ConsoleHelper;
use ZF\ComposerAutoloading\Command;

class CommandTest extends TestCase
{
    const TEST_COMMAND_NAME = 'composer-autoloading';

    public function assertHelpOutput($console, $resource = STDOUT, $command = self::TEST_COMMAND_NAME)
    {
        $console
            ->writeLine(
                Argument::containingString($command . ' [command] [options] modulename'),
                true,
                $resource
            )
            ->shouldBeCalled();
    }

    public function assertNotHelpOutput($console, $resource = STDOUT, $command = self::TEST_COMMAND_NAME)
    {
        $console
            ->writeLine(
                Argument::containingString($command . ' [command] [options] modulename'),
                true,
                $resource
            )
            ->shouldNotBeCalled();
    }

    public function helpRequest()
    {
        return [
            'no-args'                     => [[]],
            'help-command'                => [['help']],
            'help-option'                 => [['--help']],
            'help-flag'                   => [['-h']],
            'enable-command-help-option'  => [['enable', '--help']],
            'enable-command-help-flag'    => [['enable', '-h']],
            'disable-command-help-option' => [['disable', '--help']],
            'disable-command-help-flag'   => [['disable', '-h']],
        ];
    }

    /**
     * @dataProvider helpRequest
     *
     * @param string[] $args
     */
    public function testHelpRequestsEmitHelpToStdout(array $args)
    {
        $console = $this->prophesize(ConsoleHelper::class);
        $this->assertHelpOutput($console);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->assertEquals(0, $command->process($args));
    }

    public function testUnknownCommandEmitsHelpToStderrWithErrorMessage()
    {
        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Unknown command')
            )
            ->shouldBeCalled();
        $this->assertHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->assertEquals(1, $command->process(['foo', 'bar']));
    }

    public function action()
    {
        return [
            'disable' => ['disable'],
            'enable' => ['enable'],
        ];
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfNoModuleNameProvided($action)
    {
        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Invalid module name')
            )
            ->shouldBeCalled();
        $this->assertHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->assertEquals(1, $command->process([$action]));
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfInvalidNumberOfArgumentsProvided($action)
    {
        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Invalid arguments')
            )
            ->shouldBeCalled();
        $this->assertHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->assertEquals(1, $command->process([$action, 'invalid', 'module-name']));
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfUnknownArgumentProvided($action)
    {
        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Unknown argument "--invalid" provided')
            )
            ->shouldBeCalled();
        $this->assertHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->assertEquals(1, $command->process([$action, '--invalid', 'value', 'module-name']));
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfModulesDirectoryDoesNotExist($action)
    {
        vfsStream::setup('project');

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Unable to determine modules directory')
            )
            ->shouldBeCalled();
        $this->assertHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $this->assertEquals(1, $command->process([$action, 'module-name']));
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfModuleDoesNotExist($action)
    {
        $dir = vfsStream::setup('project');
        vfsStream::newDirectory('module')->at($dir);

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Could not locate module "module-name"')
            )
            ->shouldBeCalled();
        $this->assertHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $this->assertEquals(1, $command->process([$action, 'module-name']));
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfComposerDoesNotExist($action)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory('module')->at($dir);
        $this->setUpModule($modulesDir, 'module-name', 'psr4');
        $this->setUpComposerJson(
            $dir,
            $action === 'enable'
                ? []
                : ['autoload' => ['psr-4' => ['module-name\\' => 'module/path/src']]]
        );

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Unable to determine composer binary')
            )
            ->shouldBeCalled();
        $this->assertHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, $dir->url());
        $result = $command->process([$action, '--composer', vfsStream::url('composer'), 'module-name']);
        $this->assertEquals(1, $result);
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfComposerIsNotExecutable($action)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory('module')->at($dir);
        $this->setUpModule($modulesDir, 'module-name', 'psr4');
        $this->setUpComposerJson(
            $dir,
            $action === 'enable'
                ? []
                : ['autoload' => ['psr-4' => ['module-name\\' => 'module/path/src']]]
        );
        vfsStream::newFile('composer')->at($dir);

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Unable to determine composer binary')
            )
            ->shouldBeCalled();
        $this->assertHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, $dir->url());
        $result = $command->process([$action, '--composer', vfsStream::url('project/composer'), 'module-name']);
        $this->assertEquals(1, $result);
    }

    public function invalidType()
    {
        return [
            'enable-invalid-psr-0'  => ['enable', 'psr-0'],
            'enable-invalid-psr-4'  => ['enable', 'psr-4'],
            'disable-invalid-psr-0' => ['disable', 'psr-0'],
            'disable-invalid-psr-4' => ['disable', 'psr-0'],
        ];
    }

    /**
     * @dataProvider invalidType
     *
     * @param string $action
     * @param string $type
     */
    public function testCommandErrorIfInvalidTypeProvided($action, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory('module')->at($dir);
        $this->setUpModule($modulesDir, 'module-name', 'psr4');
        $this->setUpComposerJson(
            $dir,
            $action === 'enable'
                ? []
                : ['autoload' => ['psr-4' => ['module-name\\' => 'module/path/src']]]
        );

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Invalid type provided; must be one of psr0 or psr4')
            )
            ->shouldBeCalled();
        $this->assertHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, $dir->url());
        $result = $command->process([$action, '--type', $type,'module-name']);
        $this->assertEquals(1, $result);
    }

    /**
     * @dataProvider validEnable
     * @dataProvider validDisable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testErrorIfComposerJsonDoesNotExist($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('composer.json file does not exist')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--type', $type, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(1, $result);
    }

    /**
     * @dataProvider validEnable
     * @dataProvider validDisable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testErrorIfComposerJsonIsNotWritable($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        vfsStream::newFile('composer.json', 0444)->at($dir);

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('composer.json file is not writable')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--type', $type, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(1, $result);
    }

    /**
     * @dataProvider validEnable
     * @dataProvider validDisable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testErrorIfComposerJsonHasInvalidContent($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        vfsStream::newFile('composer.json')
            ->withContent('invalid content')
            ->at($dir);

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Error parsing composer.json file')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--type', $type, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(1, $result);
    }

    /**
     * @dataProvider validEnable
     * @dataProvider validDisable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testErrorIfComposerJsonHasNoContent($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        $this->setUpComposerJson($dir);

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('The composer.json file was empty')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--type', $type, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(1, $result);
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testErrorIfCannotDetermineModuleType($action)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory('module')->at($dir);
        vfsStream::newDirectory('App')->at($modulesDir);
        $this->setUpComposerJson($dir, []);

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeErrorMessage(
                Argument::containingString('Unable to determine autoloading type; no src directory found in module')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, 'App']);
        $this->assertEquals(1, $result);
    }

    public function validEnable()
    {
        return [
            'enable-psr0' => ['enable', 'module', 'psr0'],
            'enable-psr4' => ['enable', 'src', 'psr4'],
        ];
    }

    public function validDisable()
    {
        return [
            'disable-psr0' => ['disable', 'modules', 'psr0'],
            'disable-psr4' => ['disable', 'sources', 'psr4'],
        ];
    }

    /**
     * @dataProvider validEnable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testMessageOnEnableWhenModuleIsAlreadyEnabled($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        $this->setUpComposerJson(
            $dir,
            ['autoload' => [$type === 'psr0' ? 'psr-0' : 'psr-4' => ['App\\' => 'path/to/module/src']]]
        );

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeLine(
                Argument::containingString('Autoloading rules already exist for the module "App"')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(0, $result);
    }

    /**
     * @dataProvider validEnable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testSuccessMessageOnEnable($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        $this->setUpComposerJson(
            $dir,
            ['autoload' => [$type === 'psr0' ? 'psr-0' : 'psr-4' => ['App2\\' => 'app-2/path']]]
        );

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeLine(
                Argument::containingString('Successfully added composer autoloading for the module "App"')
            )
            ->shouldBeCalled();
        $console
            ->writeLine(Argument::containingString(
                'You can now safely remove the App\Module::getAutoloaderConfig() implementation'
            ))
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(0, $result);
        $composerJson = json_decode(file_get_contents(vfsStream::url('project/composer.json')), true);
        $this->assertCount(2, $composerJson['autoload'][$type === 'psr0' ? 'psr-0' : 'psr-4']);
        $this->assertEquals('app-2/path', $composerJson['autoload'][$type === 'psr0' ? 'psr-0' : 'psr-4']['App2\\']);
        $this->assertEquals(
            sprintf('%s/App/src/', $modulesPath),
            $composerJson['autoload'][$type === 'psr0' ? 'psr-0' : 'psr-4']['App\\']
        );
    }

    /**
     * @dataProvider validEnable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testSuccessMessageOnEnableAndModuleClassFileMoved($action, $modulesPath, $type)
    {
        $expectedModuleFileContent = <<< 'EOH'
<?php

namespace App;

class Module {
    public function getConfigDir()
    {
        return __DIR__ . '/../config/';
    }
}

EOH;

        $moduleFileContent = <<< 'EOH'
<?php

namespace App;

class Module {
    public function getConfigDir()
    {
        return __DIR__ . '/config/';
    }
}

EOH;

        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        $this->setUpComposerJson($dir, []);
        $moduleFile = vfsStream::newFile('Module.php')
            ->withContent($moduleFileContent)
            ->at($modulesDir->getChild('App'));

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeLine(Argument::containingString(
                'Successfully added composer autoloading for the module "App"'
            ))
            ->shouldBeCalled();
        $console
            ->writeLine(Argument::containingString(
                'You can now safely remove the App\Module::getAutoloaderConfig() implementation'
            ))
            ->shouldBeCalled();
        $console
            ->writeLine(Argument::containingString(
                sprintf('Renaming %1$s/App/Module.php to %1$s/App/src/Module.php', $modulesDir->url())
            ))
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(0, $result);
        $this->assertFileNotExists($moduleFile->url());
        $newModuleFile = vfsStream::url(sprintf('project/%s/App/src/Module.php', $modulesPath));
        $this->assertFileExists($newModuleFile);
        $this->assertEquals($expectedModuleFileContent, file_get_contents($newModuleFile));
    }

    /**
     * @dataProvider validEnable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testSuccessMessageOnEnableAndModuleClassIsNotMoved($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        $this->setUpComposerJson($dir, []);
        $moduleFile = vfsStream::newFile('Module.php')
            ->at($modulesDir->getChild('App'));

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeLine(Argument::containingString(
                'Successfully added composer autoloading for the module "App"'
            ))
            ->shouldBeCalled();
        $console
            ->writeLine(Argument::containingString(
                'You can now safely remove the App\Module::getAutoloaderConfig() implementation'
            ))
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(0, $result);
        $this->assertFileExists($moduleFile->url());
        $newModuleFile = vfsStream::url(sprintf('project/%s/App/src/Module.php', $modulesPath));
        $this->assertFileNotExists($newModuleFile);
    }

    /**
     * @dataProvider validEnable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testSuccessMessageOnEnableAndNewTypeModuleClassExists($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        $this->setUpComposerJson($dir, []);
        $moduleFile = vfsStream::newFile('Module.php')
            ->withContent('<?php' . "\n" . 'class Module {}')
            ->at($modulesDir->getChild('App'));
        $newModuleFile = vfsStream::newFile('Module.php')
            ->at($modulesDir->getChild('App')->getChild('src'));

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeLine(Argument::containingString(
                'Successfully added composer autoloading for the module "App"'
            ))
            ->shouldBeCalled();
        $console
            ->writeLine(Argument::containingString(
                'You can now safely remove the App\Module::getAutoloaderConfig() implementation'
            ))
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(0, $result);
        $this->assertFileExists($moduleFile->url());
        $this->assertFileExists($newModuleFile->url());
    }

    /**
     * @dataProvider validDisable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testMessageOnDisableWhenModulesIsAlreadyDisabled($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        $this->setUpComposerJson($dir, []);

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeLine(
                Argument::containingString('Autoloading rules already do not exist for the module "App"')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(0, $result);
    }

    /**
     * @dataProvider validDisable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testSuccessMessageOnDisable($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        $this->setUpComposerJson(
            $dir,
            ['autoload' => [$type === 'psr0' ? 'psr-0' : 'psr-4' => ['App\\' => 'app/path']]]
        );

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeLine(
                Argument::containingString('Successfully removed composer autoloading for the module "App"')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(0, $result);
        $composerJson = json_decode(file_get_contents(vfsStream::url('project/composer.json')), true);
        $this->assertArrayNotHasKey('autoload', $composerJson);
    }

    /**
     * @dataProvider validDisable
     *
     * @param string $action
     * @param string $modulesPath
     * @param string $type
     */
    public function testDisableCommandDisableOnlyProvidedModule($action, $modulesPath, $type)
    {
        $dir = vfsStream::setup('project');
        $modulesDir = vfsStream::newDirectory($modulesPath)->at($dir);
        $this->setUpModule($modulesDir, 'App', $type);
        $this->setUpComposerJson(
            $dir,
            [
                'autoload' => [
                    $type === 'psr0' ? 'psr-0' : 'psr-4' => [
                        'App\\' => 'app/path',
                        'App2\\' => 'app2/path',
                    ],
                ],
            ]
        );

        $console = $this->prophesize(ConsoleHelper::class);
        $console
            ->writeLine(
                Argument::containingString('Successfully removed composer autoloading for the module "App"')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput($console, STDERR);

        $command = new Command(self::TEST_COMMAND_NAME, $console->reveal());
        $this->setProjectDir($command, vfsStream::url('project'));
        $result = $command->process([$action, '--modules-path', $modulesPath, 'App']);
        $this->assertEquals(0, $result);
        $composerJson = json_decode(file_get_contents(vfsStream::url('project/composer.json')), true);
        $this->assertCount(1, $composerJson['autoload'][$type === 'psr0' ? 'psr-0' : 'psr-4']);
        $this->assertEquals('app2/path', $composerJson['autoload'][$type === 'psr0' ? 'psr-0' : 'psr-4']['App2\\']);
    }

    /**
     * @param vfsStreamContainer $modulesDir
     * @param string $name
     * @param string $type
     */
    protected function setUpModule(vfsStreamContainer $modulesDir, $name, $type)
    {
        vfsStream::newDirectory(sprintf('%s/src/%s', $name, $type === 'psr0' ? $name : ''))->at($modulesDir);
    }

    /**
     * @param vfsStreamContainer $dir
     * @param array|null $content
     */
    protected function setUpComposerJson(vfsStreamContainer $dir, array $content = null)
    {
        vfsStream::newFile('composer.json')
            ->withContent(json_encode($content))
            ->at($dir);
    }

    /**
     * @param Command $command
     * @param string $dir
     * @return void
     */
    protected function setProjectDir(Command $command, $dir)
    {
        $rc = new \ReflectionObject($command);
        $rp = $rc->getProperty('projectDir');
        $rp->setAccessible(true);
        $rp->setValue($command, $dir);
    }
}
