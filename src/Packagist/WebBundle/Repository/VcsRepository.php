<?php


namespace Packagist\WebBundle\Repository;

use Composer\Downloader\TransportException;
use Composer\Package\Version\VersionParser;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\InvalidRepositoryException;

/**
 * Class VcsRepository
 * Overridden VcsRepository from Composer that modifies the standard version
 * string parser / normalizer to recognize iglue repository annotations in
 * friendly version strings.
 */
class VcsRepository extends \Composer\Repository\VcsRepository
{
    protected $iglueTargetRepo;

    public function __construct(
      array $repoConfig,
      int $iglueTargetRepo,
      \Composer\IO\IOInterface $io,
      \Composer\Config $config,
      \Composer\EventDispatcher\EventDispatcher $dispatcher = null,
      array $drivers = null
    ) {
        parent::__construct($repoConfig, $io, $config, $dispatcher, $drivers);

        $this->iglueTargetRepo = $iglueTargetRepo;
    }

    protected function initialize()
    {
        $this->packages = [];

        $verbose = $this->verbose;

        $driver = $this->getDriver();
        if (!$driver) {
            throw new \InvalidArgumentException('No driver found to handle VCS repository '.$this->url);
        }

        $this->versionParser = new VersionParser;
        if (!$this->loader) {
            $this->loader = new ArrayLoader($this->versionParser);
        }

        try {
            if ($driver->hasComposerFile($driver->getRootIdentifier())) {
                $data = $driver->getComposerInformation($driver->getRootIdentifier());
                $this->packageName = !empty($data['name']) ? $data['name'] : null;
            }
        } catch (\Exception $e) {
            if ($verbose) {
                $this->io->writeError('<error>Skipped parsing '.$driver->getRootIdentifier().', '.$e->getMessage().'</error>');
            }
        }

        foreach ($driver->getTags() as $tag => $identifier) {
            $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $tag . '</comment>)';
            if ($verbose) {
                $this->io->writeError($msg);
            } else {
                $this->io->overwriteError($msg, false);
            }

            // strip the release- prefix from tags if present
            $tag = str_replace('release-', '', $tag);

            // require that the tag contains an iglue repository target annotation.
            $iv = $this->iglueTargetRepo;
            $repoTargetPattern = "{^(v?(?:\d{1,5})(?:\.\d++)?(?:\.\d++)?(?:\.\d++)?)-iglue$iv(.*)$}";
            $iglueCleanedTag = preg_replace($repoTargetPattern, '$1$2', $tag);
            if ($tag === $iglueCleanedTag) {
                // Nothing was replaced, not an iglue tag.
                continue;
            } else {
                $tag = $iglueCleanedTag;
            }

            if (!$parsedTag = $this->validateTag($tag)) {
                if ($verbose) {
                    $this->io->writeError('<warning>Skipped tag '.$tag.', invalid tag name</warning>');
                }
                continue;
            }

            try {
                if (!$data = $driver->getComposerInformation($identifier)) {
                    if ($verbose) {
                        $this->io->writeError('<warning>Skipped tag '.$tag.', no composer file</warning>');
                    }
                    continue;
                }

                // manually versioned package
                if (isset($data['version'])) {
                    $data['version_normalized'] = $this->versionParser->normalize($data['version']);
                } else {
                    // auto-versioned package, read value from tag
                    $data['version'] = $tag;
                    $data['version_normalized'] = $parsedTag;
                }

                // broken package, version doesn't match tag
                if ($data['version_normalized'] !== $parsedTag) {
                    if ($verbose) {
                        $this->io->writeError('<warning>Skipped tag '.$tag.', tag ('.$parsedTag.') does not match version ('.$data['version_normalized'].') in composer.json</warning>');
                    }
                    continue;
                }

                if ($verbose) {
                    $this->io->writeError('Importing tag '.$tag.' ('.$data['version_normalized'].')');
                }

                $this->addPackage($this->loader->load($this->preProcess($driver, $data, $identifier)));
            } catch (\Exception $e) {
                if ($verbose) {
                    $this->io->writeError('<warning>Skipped tag '.$tag.', '.($e instanceof TransportException ? 'no composer file was found' : $e->getMessage()).'</warning>');
                }
                continue;
            }
        }

        if (!$verbose) {
            $this->io->overwriteError('', false);
        }

        // Iterating through and interpreting branches into versions was here
        // in Packagist.

        $driver->cleanup();

        if (!$verbose) {
            $this->io->overwriteError('', false);
        }

        if (!$this->getPackages()) {
            throw new InvalidRepositoryException('No valid composer.json was found in any iglue-targeted tag of '.$this->url.', could not load a package from it.');
        }
    }

    private function validateTag($version)
    {
        try {
            return $this->versionParser->normalize($version);
        } catch (\Exception $e) {
        }

        return false;
    }
}