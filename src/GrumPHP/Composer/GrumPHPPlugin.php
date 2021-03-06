<?php

namespace GrumPHP\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer;
use Composer\Installer\PackageEvents;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use GrumPHP\Console\Command\Git\DeInitCommand;
use GrumPHP\Console\Command\Git\InitCommand;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Class GrumPHPPlugin
 *
 * @package GrumPHP\Composer
 */
class GrumPHPPlugin implements PluginInterface, EventSubscriberInterface
{

    const PACKAGE_NAME = 'phpro/grumphp';

    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var bool
     */
    protected $initScheduled = false;

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Attach package installation events:
     *
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            PackageEvents::POST_PACKAGE_INSTALL => 'postPackageInstall',
            PackageEvents::POST_PACKAGE_UPDATE => 'postPackageUpdate',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'prePackageUninstall',
            ScriptEvents::POST_INSTALL_CMD => 'runScheduledInit',
            ScriptEvents::POST_UPDATE_CMD => 'runScheduledInit',
        );
    }

    /**
     * When this package is updated, the git hook is also initialized
     *
     * @param PackageEvent $event
     */
    public function postPackageInstall(PackageEvent $event)
    {
        /** @var InstallOperation $operation */
        $operation = $event->getOperation();
        $package = $operation->getPackage();

        if (!$this->guardIsGrumPhpPackage($package)) {
            return;
        }

        // Schedule init when command is completed
        $this->initScheduled = true;
    }

    /**
     * When this package is updated, the git hook is also updated
     *
     * @param PackageEvent $event
     */
    public function postPackageUpdate(PackageEvent $event)
    {
        /** @var UpdateOperation $operation */
        $operation = $event->getOperation();
        $package = $operation->getTargetPackage();

        if (!$this->guardIsGrumPhpPackage($package)) {
            return;
        }

        // Schedule init when command is completed
        $this->initScheduled = true;
    }

    /**
     * When this package is uninstalled, the generated git hooks need to be removed
     *
     * @param PackageEvent $event
     */
    public function prePackageUninstall(PackageEvent $event)
    {
        /** @var UninstallOperation $operation */
        $operation = $event->getOperation();
        $package = $operation->getPackage();

        if (!$this->guardIsGrumPhpPackage($package)) {
            return;
        }

        // First remove the hook, before everything is deleted!
        $this->deInitGitHook();
    }

    /**
     * @param Event $event
     */
    public function runScheduledInit(Event $event)
    {
        if (!$this->initScheduled) {
            return;
        }
        $this->initGitHook();
    }

    /**
     * @param PackageInterface $package
     *
     * @return bool
     */
    protected function guardIsGrumPhpPackage(PackageInterface $package)
    {
        return $package->getName() == self::PACKAGE_NAME;
    }

    /**
     * Initialize git hooks
     */
    protected function initGitHook()
    {
        $this->runGrumPhpCommand(InitCommand::COMMAND_NAME);
    }

    /**
     * Deinitialize git hooks
     */
    protected function deInitGitHook()
    {
        $this->runGrumPhpCommand(DeInitCommand::COMMAND_NAME);
    }

    /**
     * Run the GrumPHP console to (de)init the git hooks
     *
     * @param $command
     */
    protected function runGrumPhpCommand($command)
    {
        $config = $this->composer->getConfig();
        $executable = $config->get('bin-dir') . '/grumphp';

        $builder = new ProcessBuilder(array('php', $executable, $command));
        $process = $builder->getProcess();

        $process->run();
        if (!$process->isSuccessful()) {
            $this->io->write(
                '<fg=red>GrumPHP can not sniff your commits. Did you specify the correct git-dir?</fg=red>'
            );
            $this->io->write('<fg=red>' . $process->getErrorOutput() . '</fg=red>');
            return;
        }

        $this->io->write('<fg=yellow>' . $process->getOutput() . '</fg=yellow>');
    }
}
