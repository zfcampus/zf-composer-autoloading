<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\ComposerAutoloading\Command;

use ZF\ComposerAutoloading\Exception;

class Disable extends AbstractCommand
{
    /**
     * Update composer.json autoloading rules.
     *
     * Removes autoloading rule from composer.json, and executes composer dump-autoload.
     *
     * @return false|string
     * @throws Exception\RuntimeException
     */
    protected function process()
    {
        if (! $this->autoloadingRulesExist()) {
            return false;
        }

        $composerPackage = $this->composerPackage;
        $type = $this->type;
        $module = $this->moduleName;

        unset($composerPackage['autoload'][$type][$module . '\\']);
        if (! $composerPackage['autoload'][$type]) {
            unset($composerPackage['autoload'][$type]);

            if (! $composerPackage['autoload']) {
                unset($composerPackage['autoload']);
            }
        }

        return $composerPackage;
    }
}
