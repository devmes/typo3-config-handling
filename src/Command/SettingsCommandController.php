<?php
declare(strict_types=1);
namespace Helhum\Typo3ConfigHandling\Command;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017 Helmut Hummel <info@helhum.io>
 *  All rights reserved
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the text file GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Helhum\Typo3Console\Mvc\Cli\CommandDispatcher;
use Helhum\Typo3Console\Mvc\Controller\CommandController;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SettingsCommandController extends CommandController
{
    /**
     * @var string
     */
    private $localConfigurationFile;

    /**
     * @var string
     */
    private $additionalConfigurationFile;

    public function __construct()
    {
        $this->localConfigurationFile = getenv('TYPO3_PATH_ROOT') . '/typo3conf/LocalConfiguration.php';
        $this->additionalConfigurationFile = getenv('TYPO3_PATH_ROOT') . '/typo3conf/AdditionalConfiguration.php';
    }

    /**
     * Dump a static LocalConfiguration.php file
     *
     * The values are complied to respect all settings managed by the configuration loader.
     *
     * @param bool $noDev When set, only LocalConfiguration.php is written to contain the merged configuration ready for production
     */
    public function dumpCommand($noDev = false)
    {
        if (file_exists($this->localConfigurationFile) && !$this->isAutoGenerated($this->localConfigurationFile)) {
            CommandDispatcher::createFromCommandRun()->executeCommand('settings:extract');
        }
        $localConfigurationFileContent = '<?php'
            . chr(10)
            . '// Auto generated by helhum/typo3-config-handling'
            . chr(10)
            . '// Do not edit this file'
            . chr(10);
        if ($noDev) {
            if ($this->isAutoGenerated($this->additionalConfigurationFile)) {
                unlink($this->additionalConfigurationFile);
            }
            $localConfigurationFileContent .= 'return ' . chr(10);
            $configLoader = \Helhum\Typo3ConfigHandling\ConfigLoader::create(
                getenv('TYPO3_PATH_COMPOSER_ROOT') . '/conf/',
                \TYPO3\CMS\Core\Utility\GeneralUtility::getApplicationContext()->isProduction() ? 'prod' : 'dev'
            );
            $localConfigurationFileContent .= ArrayUtility::arrayExport($configLoader->load());
            $localConfigurationFileContent .= ';' . chr(10);
        } else {
            copy(
                dirname(dirname(__DIR__)) . '/res/AdditionalConfiguration.php',
                $this->additionalConfigurationFile
            );
            $localConfigurationFileContent .= 'return [];' . chr(10);
        }
        file_put_contents(
            $this->localConfigurationFile,
            $localConfigurationFileContent
        );
    }

    public function extractCommand()
    {
        if (!file_exists($this->localConfigurationFile)) {
            $this->outputLine('<warning>LocalConfiguration.php does not exist. Nothing to extract</warning>');
            return;
        }
        if ($this->isAutoGenerated($this->localConfigurationFile)) {
            $this->outputLine('<info>LocalConfiguration.php is already generated. Nothing to extract.</info>');
            return;
        }
        $distExtSettingsFile = getenv('TYPO3_PATH_COMPOSER_ROOT') . '/conf/config.extension.yml';
        $typo3Settings = require $this->localConfigurationFile;
        $distExtSettings = file_exists($distExtSettingsFile) ? Yaml::parse(file_get_contents($distExtSettingsFile)) : [];
        try {
            foreach (ArrayUtility::getValueByPath($typo3Settings, 'EXT/extConf') as $extensionKey => $typo3ExtSettings) {
                if (
                    !isset($distExtSettings['EXT']['extConf'][$extensionKey])
                    || !is_array($distExtSettings['EXT']['extConf'][$extensionKey])
                ) {
                    $distExtSettings['EXT']['extConf'][$extensionKey] = [];
                }
                $distExtSettings['EXT']['extConf'][$extensionKey] = array_replace_recursive($distExtSettings['EXT']['extConf'][$extensionKey], GeneralUtility::removeDotsFromTS(unserialize($typo3ExtSettings, [false])));
            }
            $commandDispatcher = CommandDispatcher::createFromCommandRun();
            $commandDispatcher->executeCommand('configuration:remove', ['paths' => 'EXT', '--force' => true]);
            $this->outputLine('<info>Extracted extension settings to conf/config.extensions.yml</info>');
            file_put_contents(
                $distExtSettingsFile,
                Yaml::dump($distExtSettings, 5)
            );
        } catch (\RuntimeException $e) {
            $this->outputLine('<warning>No extension settings were found</warning>');
        }
    }

    private function isAutoGenerated(string $file): bool
    {
        if (!file_exists($file)) {
            return false;
        }
        return false !== strpos(file_get_contents($file), 'Auto generated by helhum/typo3-config-handling');
    }
}
