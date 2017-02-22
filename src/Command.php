<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2016-2017 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\ComposerAutoloading;

use Zend\Stdlib\ConsoleHelper;

class Command
{
    const DEFAULT_COMMAND_NAME = 'zf-composer-autoloading';

    /**
     * @var string
     */
    private $projectDir = '.';

    /**
     * @var string[]
     */
    private $commands = [
        'disable' => Command\Disable::class,
        'enable' => Command\Enable::class,
    ];

    /**
     * @var array
     */
    private $helpArgs = ['--help', '-h', 'help'];

    /**
     * @var string Composer binary name/location
     */
    private $composer = 'composer';

    /**
     * @var string One of psr-0 or psr-4
     */
    private $type;

    /**
     * @var string Filesystem path to modules directory
     */
    private $modulesPath = 'module';

    /**
     * @var string Module name
     */
    private $module;

    /**
     * @var string Filesystem path to module
     */
    private $modulePath;

    /**
     * @param string $command
     * @param null|ConsoleHelper $console
     */
    public function __construct($command = self::DEFAULT_COMMAND_NAME, ConsoleHelper $console = null)
    {
        $this->command = (string) $command;
        $this->console = $console ?: new ConsoleHelper();
    }

    /**
     * Process the command.
     *
     * Facade method that performs all tasks related to the command.
     *
     * @param array $args
     * @return int Exit status
     */
    public function process(array $args)
    {
        if ($this->isHelpRequest($args)) {
            return $this->showHelp();
        }

        $command = $this->getCommand(array_shift($args));
        if (false === $command) {
            $this->console->writeErrorMessage('Unknown command');
            return $this->showHelp(STDERR);
        }

        try {
            $this->parseArguments($args);
        } catch (Exception\InvalidArgumentException $ex) {
            $this->console->writeErrorMessage($ex->getMessage());
            return $this->showHelp(STDERR);
        }

        /** @var Command\AbstractCommand $instance */
        $instance = new $command($this->projectDir, $this->modulesPath, $this->composer);

        $isEnableCommand = $instance instanceof Command\Enable;

        try {
            if ($instance->process($this->module, $this->type)) {
                if ($isEnableCommand && ($movedModuleClass = $instance->getMovedModuleClass())) {
                    $src = key($movedModuleClass);
                    $dest = reset($movedModuleClass);

                    $this->console->writeLine(sprintf('Renaming %s to %s', $src, $dest));
                }

                $this->console->writeLine(sprintf(
                    $isEnableCommand
                        ? 'Successfully added composer autoloading for the module "%s"'
                        : 'Successfully removed composer autoloading for the module "%s"',
                    $this->module
                ));

                if ($isEnableCommand) {
                    $this->console->writeLine(sprintf(
                        'You can now safely remove the %s\\Module::getAutoloaderConfig() implementation.',
                        $this->module
                    ));
                }
            } else {
                $this->console->writeLine(sprintf(
                    $isEnableCommand
                        ? 'Autoloading rules already exist for the module "%s"'
                        : 'Autoloading rules already do not exist for the module "%s"',
                    $this->module
                ));
            }
        } catch (Exception\RuntimeException $ex) {
            $this->console->writeErrorMessage($ex->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * @param string $cmd
     * @return false|string
     */
    private function getCommand($cmd)
    {
        if (isset($this->commands[$cmd])) {
            return $this->commands[$cmd];
        }

        return false;
    }

    /**
     * Emits the help message to the provided stream.
     *
     * @param resource $resource
     * @return int
     */
    private function showHelp($resource = STDOUT)
    {
        $help = new Help($this->command, $this->console);
        $help($resource);

        return $resource === STDERR ? 1 : 0;
    }

    /**
     * Is this a help request?
     *
     * @param array $args
     * @return bool
     */
    private function isHelpRequest(array $args)
    {
        $numArgs = count($args);
        if (0 === $numArgs) {
            return true;
        }

        $arg = array_shift($args);

        if (in_array($arg, $this->helpArgs, true)) {
            return true;
        }

        if (empty($args)) {
            return false;
        }

        $arg = array_shift($args);

        return in_array($arg, $this->helpArgs, true);
    }

    /**
     * Parse arguments
     *
     * Parses arguments for:
     *
     * - Module name (validating that a path representing that module exists)
     * - No unexpected arguments
     * - --composer/-c argument, if present, represents a valid composer binary
     * - --type/-t argument, if present, is valid
     * - --modules-path/-p argument, if present, represents a valid path to modules directory
     *
     * Sets the module, modulePath, composer, type and modulesPath properties.
     *
     * @param array $args
     * @return void
     * @throws Exception\InvalidArgumentException If invalid argument detected.
     */
    private function parseArguments(array $args)
    {
        // Get module argument (always expected in last position)
        $this->module = array_pop($args);
        if (! $this->module) {
            throw new Exception\InvalidArgumentException('Invalid module name');
        }

        // Parse arguments
        $args = array_values($args);
        $count = count($args);

        if (0 !== $count % 2) {
            throw new Exception\InvalidArgumentException('Invalid arguments');
        }

        for ($i = 0; $i < $count; $i += 2) {
            switch ($args[$i]) {
                case '--composer':
                    // fall-through
                case '-c':
                    $this->composer = $args[$i + 1];
                    break;

                case '--type':
                    // fall-through
                case '-t':
                    $this->type = $args[$i + 1];
                    if (! in_array($this->type, ['psr0', 'psr4'], true)) {
                        throw new Exception\InvalidArgumentException(
                            'Invalid type provided; must be one of psr0 or psr4'
                        );
                    }

                    $this->type = preg_replace('/^(psr)([04])$/', '$1-$2', $this->type);
                    break;

                case '--modules-path':
                    // fall-through
                case '-p':
                    $this->modulesPath = preg_replace('/^\.\//', '', str_replace('\\', '/', $args[$i + 1]));
                    break;

                default:
                    throw new Exception\InvalidArgumentException(sprintf(
                        'Unknown argument "%s" provided',
                        $args[$i]
                    ));
            }
        }

        $this->modulePath = sprintf('%s/%s/%s', $this->projectDir, $this->modulesPath, $this->module);

        $this->checkArguments();
    }

    /**
     * Checks if arguments of the script are correct.
     *
     * @return void
     * @throws Exception\InvalidArgumentException
     */
    private function checkArguments()
    {
        $output = [];
        $returnVar = null;
        exec(sprintf('%s 2>&1', $this->composer), $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception\InvalidArgumentException(
                'Unable to determine composer binary'
            );
        }

        if (! is_dir(sprintf('%s/%s', $this->projectDir, $this->modulesPath))) {
            throw new Exception\InvalidArgumentException(
                'Unable to determine modules directory'
            );
        }

        if (! is_dir($this->modulePath)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Could not locate module "%s" in path "%s"',
                $this->module,
                $this->modulePath
            ));
        }
    }
}
