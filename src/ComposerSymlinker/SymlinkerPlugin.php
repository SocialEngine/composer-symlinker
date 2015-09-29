<?php namespace ComposerSymlinker;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class SymlinkerPlugin implements PluginInterface
{

    /**
     * Just add the \ComposerSymlinker\LocalInstaller new installer
     *
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getInstallationManager()->addInstaller(
            new LocalInstaller($io, $composer)
        );
    }

}
