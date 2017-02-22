<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\ComposerAutoloading;

use Zend\Stdlib\ConsoleHelper;

class Help
{
    const TEMPLATE = <<< 'EOT'
<info>Usage:</info>

  %s [command] [options] modulename

<info>Commands:</info>

  <info>help</info>          Display this help/usage message
  <info>enable</info>        Enable composer-based autoloading for the module
  <info>disable</info>       Disable composer-based autoloading for the module

<info>Options:</info>

  <info>--help|-h</info>            Display this help/usage message
  <info>--composer|-c</info>        Specify the path to the composer binary;
                       defaults to "composer"
  <info>--type|-t <psr0|psr4></info>    Specify the autoloading type to use;
                       if not provided, attempts to
                       autodetermine the type, defaults to
                       PSR-0 autoloading if unable to determine it.
  <info>--modules-path|-p</info>    Specify the path to the modules directory;
                       defaults to "module"

EOT;

    /**
     * @var string
     */
    private $command;

    /**
     * @var ConsoleHelper
     */
    private $helper;

    /**
     * @param string $command Name of script invoking the command.
     * @param ConsoleHelper $helper
     */
    public function __construct($command, ConsoleHelper $helper)
    {
        $this->command = $command;
        $this->helper = $helper;
    }

    /**
     * Emit the help message.
     *
     * @param resource $resource Stream to which to write; defaults to STDOUT.
     * @return void
     */
    public function __invoke($resource = STDOUT)
    {
        // Find relative command path
        $command = strtr(realpath($this->command) ?: $this->command, [
            getcwd() . DIRECTORY_SEPARATOR => '',
            'zfcampus' . DIRECTORY_SEPARATOR . 'zf-composer-autoloading' . DIRECTORY_SEPARATOR => '',
        ]);

        $this->helper->writeLine(sprintf(
            self::TEMPLATE,
            $command
        ), true, $resource);
    }
}
