<?php namespace ComposerSymlinker;

use Composer\Composer;
use Composer\Installer\InstallerInterface;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

/**
 * Local package installer manager
 */
class LocalInstaller extends LibraryInstaller
{
    protected $localDirs = [];
    protected $localVendors = [];
    protected $localPackages = [];

    /**
     * @var \Composer\Installer\InstallationManager
     */
    private $installManager;

    /**
     * {@inheritDoc}
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null)
    {
        parent::__construct($io, $composer, $type, $filesystem);

        $this->installManager = clone $this->composer->getInstallationManager();

        $extra = $composer->getPackage()->getExtra();
        $this->setLocalDirs(
            isset($extra['local-dirs']) ? $extra['local-dirs'] : dirname(getcwd())
        );
        if (isset($extra['local-vendors'])) {
            $this->setLocalVendors($extra['local-vendors']);
        }
        if (isset($extra['local-packages'])) {
            $this->setLocalPackages($extra['local-packages']);
        }
    }

    /**
     * Define a list of local paths to scan (extra 'local-dirs')
     *
     * @param   string|array $dirs
     * @return  $this
     * @throws  \InvalidArgumentException if a path does not exist
     */
    public function setLocalDirs($dirs)
    {
        $dirs = is_array($dirs) ? $dirs : array($dirs);
        foreach ($dirs as $i => $dir) {
            if (!file_exists($dir)) {
                throw new \InvalidArgumentException(
                    sprintf('Local path not found: %s', $dir)
                );
            }
            $dirs[$i] = rtrim($dir, '/');
        }
        $this->localDirs = $dirs;

        return $this;
    }

    /**
     * Define a list of local vendors to restrict local directories scanning (extra 'local-vendors')
     *
     * @param   string|array $vendors
     * @return  $this
     */
    public function setLocalVendors($vendors)
    {
        $this->localVendors = is_array($vendors) ? $vendors : array($vendors);

        return $this;
    }

    /**
     * An array of `vendor/package => local_path` items for "per package" location (extra 'local-packages')
     *
     * @param   array $paths
     * @return  $this
     * @throws  \InvalidArgumentException if the `local_path` does not exist or does not seem to be a valid composer package
     */
    public function setLocalPackages(array $paths)
    {
        foreach ($paths as $name => $path) {
            $realPath = realpath($path);
            $paths[$name] = $realPath;

            if (!$this->isValidLocalPackage($realPath)) {
                $this->io->write(sprintf(
                    '<warning>Local path "%s" defined for package "%s" is not valid, using fallback.</warning>',
                    $path,
                    $name
                ));

                unset($paths[$name]);
            }
        }
        $this->localPackages = $paths;

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $packageType
     * @return bool
     */
    public function supports($packageType)
    {
        try {
            return $this->installManager->getInstaller($packageType) instanceof InstallerInterface;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Return the install path based on package type.
     *
     * @param  PackageInterface $package
     * @return string
     */
    public function getInstallPath(PackageInterface $package)
    {
        $path = $this->installManager->getInstallPath($package);

        if (!$this->filesystem->isAbsolutePath($path)) {
            return realpath('.') . DIRECTORY_SEPARATOR . rtrim($path, '/');
        }

        return $path;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \ComposerSymlinker\FilesystemSymlinkerException if the symbolic link fails
     *
     * @link    https://github.com/symfony/Filesystem/blob/master/Filesystem.php#L310
     */
    protected function installCode(PackageInterface $package)
    {
        $localPath = $this->getLocalPackagePath($package);

        if (is_null($localPath)) {
            return parent::installCode($package);
        }

        $this->io->write(sprintf(
            '  - Installing <info>%s</info> (<comment>as a symbolic link of %s</comment>)',
            $package->getName(),
            $localPath
        ));

        $this->initializeVendorSubdir($package);
        $installPath = $this->getInstallPath($package);
        $shortestPath = $this->filesystem->findShortestPath($installPath, $localPath, false);
        if (true !== @symlink($shortestPath, $this->getInstallPath($package))) {
            throw new FilesystemSymlinkerException(
                sprintf('Symlinking failed: %s -> %s', $localPath, $this->getInstallPath($package))
            );
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function updateCode(PackageInterface $initial, PackageInterface $target)
    {
        if ($this->isLocalSymlink($initial)) {
            if ($this->getInstallPath($initial) !== $this->getInstallPath($target)) {
                $this->initializeVendorSubdir($target);
                $this->filesystem->rename($this->getInstallPath($initial), $this->getInstallPath($target));
            }

            return true;
        }

        return parent::updateCode($initial, $target);
    }

    /**
     * {@inheritDoc}
     */
    protected function removeCode(PackageInterface $package)
    {
        if ($this->isLocalSymlink($package)) {
            $this->filesystem->unlink($this->getInstallPath($package));

            return true;
        }

        return parent::removeCode($package);
    }

    /**
     * Test if a path is a symbolic link made by the plugin (or seems to be)
     *
     * @param   string $path
     * @return  bool
     */
    public function isLocalSymlink($path)
    {
        $link = readlink($path);
        if ($link === $path) {
            return false;
        }

        // declared paths
        if (in_array($link, $this->localPackages)) {
            return $this->isValidLocalPackage($link);
        }

        // local directories
        foreach ($this->localDirs as $dir) {
            if (substr($link, 0, strlen($dir)) == $dir) {
                return $this->isValidLocalPackage($link);
            }
        }

        return false;
    }

    /**
     * Tests if a local path seems to be a valid Composer package
     *
     * @param   string $path
     * @return  bool
     */
    public function isValidLocalPackage($path)
    {
        return (bool)(file_exists($path) && is_dir($path) && file_exists($path . DIRECTORY_SEPARATOR . 'composer.json'));
    }

    /**
     * Get the target path of a local package if it is found
     *
     * @param \Composer\Package\PackageInterface $package
     * @return null|string
     */
    protected function getLocalPackagePath(PackageInterface $package)
    {
        // vendors restriction ?
        $vendor = $this->getPackageVendorName($package);
        if (!empty($this->localVendors) && !in_array($vendor, $this->localVendors)) {
            return null;
        }

        // declared paths
        if (array_key_exists($package->getPrettyName(), $this->localPackages)) {
            return $this->localPackages[$package->getPrettyName()];
        }

        // local directories
        foreach ($this->localDirs as $dir) {
            $local = $dir . '/' . $package->getPrettyName();
            if ($this->isValidLocalPackage($local)) {
                return $local;
            }
        }

        return null;
    }

    /**
     * Get a package vendor name
     *
     * I'm sure there is a way to get the vendor name in the original Composer package already
     * but can't put a hand on it ...
     *
     * @param \Composer\Package\PackageInterface $package
     * @return mixed
     *
     * @TODO replace this method by an internal one
     */
    protected function getPackageVendorName(PackageInterface $package)
    {
        list($vendor, $name) = explode('/', $package->getName());

        return $vendor;
    }

    /**
     * Be sure to create a `vendor/my_vendor` directory before to create symbolic link
     *
     * @param \Composer\Package\PackageInterface $package
     */
    protected function initializeVendorSubdir(PackageInterface $package)
    {
        $this->initializeVendorDir();
        $this->filesystem->ensureDirectoryExists(
            $this->vendorDir . DIRECTORY_SEPARATOR . $this->getPackageVendorName($package)
        );
    }

}
