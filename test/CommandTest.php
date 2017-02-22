<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZFTest\ComposerAutoloading;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use ReflectionObject;
use Zend\Stdlib\ConsoleHelper;
use ZF\ComposerAutoloading\Command;
use ZF\ComposerAutoloading\Exception;

class CommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ProjectSetupTrait;

    const TEST_COMMAND_NAME = 'zf-composer-autoloading';

    /** @var vfsStreamDirectory */
    private $dir;

    /** @var ConsoleHelper|ObjectProphecy */
    private $console;

    /** @var Command */
    private $command;

    protected function setUp()
    {
        parent::setUp();

        $this->dir = vfsStream::setup('project');

        $this->console = $this->prophesize(ConsoleHelper::class);

        $this->command = new Command(self::TEST_COMMAND_NAME, $this->console->reveal());
        $this->setProjectDir($this->command, $this->dir->url());
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
        $this->assertHelpOutput();
        $this->assertEquals(0, $this->command->process($args));
    }

    public function argument()
    {
        return [
            // $action, $argument,        $value,          $propertyName, $expectedValue
            ['enable',  '--composer',     'foo/bar',       'composer',    'foo/bar'],
            ['enable',  '-c',             'bar/baz',       'composer',    'bar/baz'],
            ['enable',  '--modules-path', './foo/modules', 'modulesPath', 'foo/modules'],
            ['enable',  '-p',             'bar\path',      'modulesPath', 'bar/path'],
            ['enable',  '--type',         'psr0',          'type',        'psr-0'],
            ['enable',  '--type',         'psr0',          'type',        'psr-0'],
            ['enable',  '-t',             'psr4',          'type',        'psr-4'],
            ['enable',  '-t',             'psr4',          'type',        'psr-4'],
            ['disable', '--composer',     'foo/bar',       'composer',    'foo/bar'],
            ['disable', '-c',             'bar/baz',       'composer',    'bar/baz'],
            ['disable', '--modules-path', 'foo/modules',   'modulesPath', 'foo/modules'],
            ['disable', '-p',             'bar/path',      'modulesPath', 'bar/path'],
            ['disable', '--type',         'psr0',          'type',        'psr-0'],
            ['disable', '--type',         'psr0',          'type',        'psr-0'],
            ['disable', '-t',             'psr4',          'type',        'psr-4'],
            ['disable', '-t',             'psr4',          'type',        'psr-4'],
        ];
    }

    /**
     * @dataProvider argument
     *
     * @param string $action
     * @param string $argument
     * @param string $value
     * @param string $propertyName
     * @param string $expectedValue
     */
    public function testArgumentIsSetAndHasExpectedValue($action, $argument, $value, $propertyName, $expectedValue)
    {
        $this->command->process([$action, $argument, $value, 'module-name']);

        $this->assertAttributeSame($expectedValue, $propertyName, $this->command);
    }

    public function testDefaultArgumentsValues()
    {
        $this->assertAttributeSame('module', 'modulesPath', $this->command);
        $this->assertAttributeSame('composer', 'composer', $this->command);
        $this->assertAttributeSame(null, 'type', $this->command);
    }

    public function testUnknownCommandEmitsHelpToStderrWithErrorMessage()
    {
        $this->console
            ->writeErrorMessage(Argument::containingString('Unknown command'))
            ->shouldBeCalled();
        $this->assertHelpOutput(STDERR);

        $this->assertEquals(1, $this->command->process(['foo', 'bar']));
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
        $this->console
            ->writeErrorMessage(Argument::containingString('Invalid module name'))
            ->shouldBeCalled();
        $this->assertHelpOutput(STDERR);

        $this->assertEquals(1, $this->command->process([$action]));
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfInvalidNumberOfArgumentsProvided($action)
    {
        $this->console
            ->writeErrorMessage(Argument::containingString('Invalid arguments'))
            ->shouldBeCalled();
        $this->assertHelpOutput(STDERR);

        $this->assertEquals(1, $this->command->process([$action, 'invalid', 'module-name']));
    }

    /**
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfUnknownArgumentProvided($action)
    {
        $this->console
            ->writeErrorMessage(Argument::containingString('Unknown argument "--invalid" provided'))
            ->shouldBeCalled();
        $this->assertHelpOutput(STDERR);

        $this->assertEquals(1, $this->command->process([$action, '--invalid', 'value', 'module-name']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfModulesDirectoryDoesNotExist($action)
    {
        $this->console
            ->writeErrorMessage(Argument::containingString('Unable to determine modules directory'))
            ->shouldBeCalled();
        $this->assertHelpOutput(STDERR);
        $this->assertComposerBinaryExecutable();

        $this->assertEquals(1, $this->command->process([$action, 'module-name']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfModuleDoesNotExist($action)
    {
        vfsStream::newDirectory('module')->at($this->dir);

        $this->console
            ->writeErrorMessage(Argument::containingString('Could not locate module "module-name"'))
            ->shouldBeCalled();
        $this->assertHelpOutput(STDERR);
        $this->assertComposerBinaryExecutable();

        $this->assertEquals(1, $this->command->process([$action, 'module-name']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider action
     *
     * @param string $action
     */
    public function testCommandErrorIfComposerIsNotExecutable($action)
    {
        $modulesDir = vfsStream::newDirectory('module')->at($this->dir);
        $this->setUpModule($modulesDir, 'module-name', 'psr4');
        $this->setUpComposerJson($this->dir, []);

        $this->console
            ->writeErrorMessage(Argument::containingString('Unable to determine composer binary'))
            ->shouldBeCalled();
        $this->assertHelpOutput(STDERR);
        $this->assertComposerBinaryNotExecutable();

        $this->assertEquals(1, $this->command->process([$action, 'module-name']));
    }

    public function invalidType()
    {
        return [
            'enable-invalid-psr-0'  => ['enable', 'psr-0'],
            'enable-invalid-psr-4'  => ['enable', 'psr-4'],
            'disable-invalid-psr-0' => ['disable', 'psr-0'],
            'disable-invalid-psr-4' => ['disable', 'psr-4'],
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
        $modulesDir = vfsStream::newDirectory('module')->at($this->dir);
        $this->setUpModule($modulesDir, 'module-name', 'psr4');
        $this->setUpComposerJson($this->dir, []);

        $this->console
            ->writeErrorMessage(Argument::containingString('Invalid type provided; must be one of psr0 or psr4'))
            ->shouldBeCalled();
        $this->assertHelpOutput(STDERR);

        $result = $this->command->process([$action, '--type', $type, 'module-name']);
        $this->assertEquals(1, $result);
    }

    public function type()
    {
        return [
            'psr-0' => ['psr0'],
            'psr-4' => ['psr4'],
        ];
    }

    /**
     * @runInSeparateProcess
     *
     * @dataProvider type
     *
     * @param string $type
     */
    public function testErrorMessageWhenActionProcessThrowsException($type)
    {
        Mockery::mock('overload:' . MyTestingCommand::class)
            ->shouldReceive('process')
            ->with('App', $type === 'psr0' ? 'psr-0' : 'psr-4')
            ->andThrow(Exception\RuntimeException::class, 'Testing Exception Message')
            ->once();

        $modulesDir = vfsStream::newDirectory('module')->at($this->dir);
        $this->setUpModule($modulesDir, 'App', $type);

        $this->console
            ->writeErrorMessage(Argument::containingString('Testing Exception Message'))
            ->shouldBeCalled();
        $this->assertNotHelpOutput(STDERR);
        $this->assertComposerBinaryExecutable();

        $this->injectCommand($this->command, 'my-command', MyTestingCommand::class);
        $this->assertEquals(1, $this->command->process(['my-command', '--type', $type, 'App']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider type
     *
     * @param string $type
     */
    public function testMessageOnEnableWhenModuleIsAlreadyEnabled($type)
    {
        Mockery::mock('overload:' . Command\Enable::class)
            ->shouldReceive('process')
            ->with('App', null)
            ->andReturn(false)
            ->once();

        $modulesDir = vfsStream::newDirectory('module')->at($this->dir);
        $this->setUpModule($modulesDir, 'App', $type);

        $this->console
            ->writeLine(Argument::containingString('Autoloading rules already exist for the module "App"'))
            ->shouldBeCalled();
        $this->assertNotHelpOutput(STDERR);
        $this->assertComposerBinaryExecutable();

        $this->assertEquals(0, $this->command->process(['enable', 'App']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider type
     *
     * @param string $type
     */
    public function testSuccessMessageOnEnable($type)
    {
        $mock = Mockery::mock('overload:' . Command\Enable::class);
        $mock
            ->shouldReceive('process')
            ->with('App', null)
            ->andReturn(true)
            ->once();
        $mock
            ->shouldReceive('getMovedModuleClass')
            ->withNoArgs()
            ->andReturnNull()
            ->once();

        $modulesDir = vfsStream::newDirectory('module')->at($this->dir);
        $this->setUpModule($modulesDir, 'App', $type);

        $this->console
            ->writeLine(Argument::containingString('Successfully added composer autoloading for the module "App"'))
            ->shouldBeCalled();
        $this->console
            ->writeLine(Argument::containingString(
                'You can now safely remove the App\Module::getAutoloaderConfig() implementation'
            ))
            ->shouldBeCalled();
        $this->assertNotHelpOutput(STDERR);
        $this->assertComposerBinaryExecutable();

        $this->assertEquals(0, $this->command->process(['enable', 'App']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider type
     *
     * @param string $type
     */
    public function testSuccessMessageOnEnableAndModuleClassFileMoved($type)
    {
        $mock = Mockery::mock('overload:' . Command\Enable::class);
        $mock
            ->shouldReceive('process')
            ->with('App', null)
            ->andReturn(true)
            ->once();
        $mock
            ->shouldReceive('getMovedModuleClass')
            ->withNoArgs()
            ->andReturn(['from-foo' => 'too-bar'])
            ->once();

        $modulesDir = vfsStream::newDirectory('module')->at($this->dir);
        $this->setUpModule($modulesDir, 'App', $type);

        $this->console
            ->writeLine(Argument::containingString('Successfully added composer autoloading for the module "App"'))
            ->shouldBeCalled();
        $this->console
            ->writeLine(Argument::containingString(
                'You can now safely remove the App\Module::getAutoloaderConfig() implementation'
            ))
            ->shouldBeCalled();
        $this->console
            ->writeLine(Argument::containingString('Renaming from-foo to too-bar'))
            ->shouldBeCalled();
        $this->assertNotHelpOutput(STDERR);
        $this->assertComposerBinaryExecutable();

        $this->assertEquals(0, $this->command->process(['enable', 'App']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider type
     *
     * @param string $type
     */
    public function testMessageOnDisableWhenModulesIsAlreadyDisabled($type)
    {
        Mockery::mock('overload:' . Command\Disable::class)
            ->shouldReceive('process')
            ->with('App', null)
            ->andReturn(false)
            ->once();

        $modulesDir = vfsStream::newDirectory('module')->at($this->dir);
        $this->setUpModule($modulesDir, 'App', $type);

        $this->console
            ->writeLine(Argument::containingString('Autoloading rules already do not exist for the module "App"'))
            ->shouldBeCalled();
        $this->assertNotHelpOutput(STDERR);
        $this->assertComposerBinaryExecutable();

        $this->assertEquals(0, $this->command->process(['disable', 'App']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider type
     *
     * @param string $type
     */
    public function testSuccessMessageOnDisable($type)
    {
        Mockery::mock('overload:' . Command\Disable::class)
            ->shouldReceive('process')
            ->with('App', null)
            ->andReturn(true)
            ->once();

        $modulesDir = vfsStream::newDirectory('module')->at($this->dir);
        $this->setUpModule($modulesDir, 'App', $type);

        $this->console
            ->writeLine(
                Argument::containingString('Successfully removed composer autoloading for the module "App"')
            )
            ->shouldBeCalled();
        $this->assertNotHelpOutput(STDERR);
        $this->assertComposerBinaryExecutable();

        $this->assertEquals(0, $this->command->process(['disable', 'App']));
    }

    /**
     * @param Command $command
     * @param string $cmd
     * @param string $class
     * @return void
     */
    private function injectCommand(Command $command, $cmd, $class)
    {
        $rCommand = new ReflectionObject($command);
        $rp = $rCommand->getProperty('commands');
        $rp->setAccessible(true);

        $commands = $rp->getValue($command);
        $commands[$cmd] = $class;

        $rp->setValue($command, $commands);
    }

    /**
     * @param Command $command
     * @param string $dir
     * @return void
     */
    private function setProjectDir(Command $command, $dir)
    {
        $rc = new ReflectionObject($command);
        $rp = $rc->getProperty('projectDir');
        $rp->setAccessible(true);
        $rp->setValue($command, $dir);
    }

    private function assertHelpOutput($resource = STDOUT, $command = self::TEST_COMMAND_NAME)
    {
        $this->console
            ->writeLine(
                Argument::containingString($command . ' [command] [options] modulename'),
                true,
                $resource
            )
            ->shouldBeCalled();
    }

    private function assertNotHelpOutput($resource = STDOUT, $command = self::TEST_COMMAND_NAME)
    {
        $this->console
            ->writeLine(
                Argument::containingString($command . ' [command] [options] modulename'),
                true,
                $resource
            )
            ->shouldNotBeCalled();
    }

    private function assertComposerBinaryNotExecutable()
    {
        $exec = $this->getFunctionMock('ZF\ComposerAutoloading', 'exec');
        $exec->expects($this->once())->willReturnCallback(function ($command, &$output, &$retValue) {
            $this->assertEquals('composer 2>&1', $command);
            $retValue = 1;
        });
    }

    private function assertComposerBinaryExecutable()
    {
        $exec = $this->getFunctionMock('ZF\ComposerAutoloading', 'exec');
        $exec->expects($this->once())->willReturnCallback(function ($command, &$output, &$retValue) {
            $this->assertEquals('composer 2>&1', $command);
            $retValue = 0;
        });
    }
}
