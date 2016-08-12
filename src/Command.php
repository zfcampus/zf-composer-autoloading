<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2016 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\ComposerAutoloading;

class Command
{
    /**
     * @var string Composer binary name/location
     */
    private $composer;

    /**
     * Default argument values.
     *
     * @param array
     */
    private $defaults = [
        'composer' => 'composer',
        'type' => false,
    ];

    /**
     * @var string Path to composer.json
     */
    private $composerJsonFile;

    /**
     * @var array Parsed composer.json contents
     */
    private $composerPackage;

    /**
     * @var string Module name
     */
    private $module;

    /**
     * @var string Filesystem path to module
     */
    private $modulePath;

    /**
     * @var string Working path
     */
    private $path;

    /**
     * @var string One of psr-0 or psr-4
     */
    private $type;

    /**
     * @var string $path
     */
    public function __construct($path)
    {
        $this->path = $path;
        $this->composerJsonFile = sprintf('%s/composer.json', $path);
    }

    /**
     * Invoke the command.
     *
     * Facade method that performs all tasks related to the command.
     *
     * @param array $args
     * @return int Exit status
     */
    public function __invoke(array $args)
    {
        $this->setDefaultArguments();

        if ($this->helpRequested($args)) {
            $this->help();
            return 0;
        }

        if (! $this->validateComposerJson()
            || ! $this->parseArguments($args)
            || ! $this->autodiscoverModuleType()
        ) {
            return 1;
        }

        if ($this->autoloadingRulesExist()) {
            return 0;
        }

        $this->moveModuleClassFile();
        $this->updateAutoloadingRules();
        $this->reportSuccess();

        return 0;
    }

    /**
     * Emit the help message to the provided stream.
     *
     * @param resource $stream
     * @return void
     */
    private function help($stream = STDOUT)
    {
        $message = <<<'EOH'
Provide Composer-based autoloading for a Zend Framework module.

Usage:

  autoload-module-via-composer [help|--help|-h] [--composer|-c <composer path>] [--type|-t psr0|psr4] modulename

Arguments:

  - [help|--help|-h]                    Display this help message.
  - [--composer|-c <composer path>]     Provide the path to the composer
                                        binary; defaults to "composer"
  - [--type|-t <psr0|psr4>]             Provide the autoloading type to
                                        use; if not provided, attempts to
                                        autodetermine the type, default to
                                        PSR-0 autoloading if unable to
                                        determine it.
  - modulename                          The name of the module for which
                                        to provide composer autoloading.

EOH;

        $message = strtr($message, "\n", PHP_EOL);

        fwrite($stream, $message);
    }

    /**
     * Set defaults before execution.
     *
     * @return void
     */
    public function setDefaultArguments()
    {
        foreach ($this->defaults as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /**
     * Was help requested?
     *
     * @param array $args
     * @return bool
     */
    private function helpRequested(array $args)
    {
        // Check for no arguments, or a help argument
        if (2 > count($args)) {
            return true;
        }

        if (in_array($args[1], ['help', '--help', '-h'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Validate that the composer.json exists, is writable, and contains valid contents.
     *
     * @return bool
     */
    private function validateComposerJson()
    {
        if (! is_readable($this->composerJsonFile)) {
            fwrite(STDERR, 'composer.json file does not exist or is not readable' . PHP_EOL);
            return false;
        }
        if (! is_writable($this->composerJsonFile)) {
            fwrite(STDERR, 'composer.json file is not writable' . PHP_EOL);
            return false;
        }

        $composerJson = file_get_contents($this->composerJsonFile);
        $composerPackage = json_decode($composerJson, true);
        if (! is_array($composerPackage)) {
            $error = json_last_error();
            $error = $error === JSON_ERROR_NONE
                ? 'The composer.json file was empty'
                : 'Error parsing composer.json file; please check that it is valid';
            fwrite(STDERR, $error . PHP_EOL);
            return false;
        }

        $this->composerPackage = $composerPackage;
        return true;
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
     *
     * Sets the module, modulePath, composer, and type properties.
     *
     * @param array $args
     * @return bool Boolean false if invalid arguments detected
     */
    private function parseArguments(array $args)
    {
        // Remove script argument
        array_shift($args);

        // Get module argument (always expected in last position)
        $this->module = $module = array_pop($args);
        $this->modulePath = $modulePath = sprintf('%s/module/%s', $this->path, $module);

        if (! is_dir($modulePath)) {
            fwrite(STDERR, sprintf('Could not locate module "%s" in path "%s"%s', $module, $modulePath, PHP_EOL));
            return false;
        }

        // Parse arguments
        if (empty($args)) {
            return true;
        }

        $args = array_values($args);
        if (0 !== $args % 2) {
            fwrite(STDERR, 'Invalid arguments' . PHP_EOL . PHP_EOL);
            $this->help(STDERR);
            return false;
        }

        for ($i = 0; $i < count($args); $i += 2) {
            $flag = $args[$i];

            switch ($args[$i]) {
                case '--composer':
                    // fall-through
                case '-c':
                    $this->composer = $args[$i + 1];
                    if (! is_executable($this->composer)) {
                        fwrite(
                            STDERR,
                            'Provided composer binary does not exist or is not executable' . PHP_EOL . PHP_EOL
                        );
                        $this->help(STDERR);
                        return false;
                    }
                    break;

                case '--type':
                    // fall-through
                case '-t':
                    $this->type = $args[$i + 1];
                    if (! in_array($this->type, ['psr0', 'psr4'], true)) {
                        fwrite(STDERR, 'Invalid type provided; must be one of psr0 or psr4' . PHP_EOL . PHP_EOL);
                        $this->help(STDERR);
                        return false;
                    }

                    $this->type = preg_replace('/^(psr)([04])$/', '$1-$2', $this->type);
                    break;

                default:
                    fwrite(STDERR, sprintf('Unknown option "%s" provided%s', $args[$i], str_repeat(PHP_EOL, 2)));
                    $this->help(STDERR);
                    return false;
            }
        }

        return true;
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
     * @return bool
     */
    private function autodiscoverModuleType()
    {
        if ($this->type) {
            return true;
        }

        $psr0Spec = sprintf('%s/src/%s', $this->modulePath, $this->module);
        if (is_dir($psr0Spec)) {
            $this->type = 'psr-0';
            return true;
        }

        $srcPath = sprintf('%s/src', $this->modulePath);
        if (! is_dir($srcPath)) {
            fwrite(STDERR, 'Unable to determine autoloading type; no src directory found in module' . PHP_EOL);
            return false;
        }

        $this->type = 'psr-4';
        return true;
    }

    /**
     * Do autoloading rules already exist for this module?
     *
     * @return bool
     */
    private function autoloadingRulesExist()
    {
        if (! isset($this->composerPackage['autoload'][$this->type][$this->module . '\\'])) {
            return false;
        }

        fwrite(STDOUT, sprintf(
            'Autoloading rules already exist for the module "%s"%s',
            $this->module,
            PHP_EOL
        ));
        return true;
    }

    /**
     * Moves the Module class file under the src tree, if necessary.
     *
     * @return void
     */
    private function moveModuleClassFile()
    {
        $moduleClassFile = sprintf('%s/Module.php', $this->modulePath);
        if (! file_exists($moduleClassFile)) {
            return;
        }

        $moduleClassContents = file_get_contents($moduleClassFile);
        if (! preg_match('#\bclass Module\b#s', $moduleClassContents)) {
            return;
        }

        $srcModuleClassFile = sprintf('%s/src/Module.php', $this->modulePath);
        if (file_exists($srcModuleClassFile)) {
            return;
        }

        fwrite(STDOUT, sprintf('Renaming %s to %s%s', $moduleClassFile, $srcModuleClassFile, PHP_EOL));
        $moduleClassContents = preg_replace('#(__DIR__ \. \')(/config/)#', '$1/..$2', $moduleClassContents);
        file_put_contents($srcModuleClassFile, $moduleClassContents);
        unlink($moduleClassFile);
    }

    /**
     * Update composer.json autoloading rules.
     *
     * Writes new rules to composer.json, and executes composer dump-autoload.
     *
     * @return void
     */
    private function updateAutoloadingRules()
    {
        $composerPackage = $this->composerPackage;
        $type = $this->type;
        $module = $this->module;

        $composerPackage['autoload'][$type][$module . '\\'] = sprintf('module/%s/src/', $module);
        file_put_contents($this->composerJsonFile, json_encode(
            $composerPackage,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $command = sprintf('%s dump-autoload', $this->composer);
        system($command);
    }

    /**
     * Emit a success message.
     *
     * @return void
     */
    private function reportSuccess()
    {
        fwrite(STDOUT, sprintf(
            'Successfully added composer autoloading for module "%s"%s',
            $this->module,
            str_repeat(PHP_EOL, 2)
        ));
        fwrite(STDOUT, sprintf(
            'You can now safely remove the %s\\Module::getAutoloaderConfig() implementation.%s',
            $this->module,
            PHP_EOL
        ));
    }
}
