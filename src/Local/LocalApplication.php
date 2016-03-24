<?php
namespace Platformsh\Cli\Local;

use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Local\Toolstack\ToolstackInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class LocalApplication
{

    protected $appRoot;
    protected $config;
    protected $sourceDir;

    /**
     * @param string $appRoot
     * @param string $sourceDir
     */
    public function __construct($appRoot, $sourceDir = null)
    {
        if (!is_dir($appRoot)) {
            throw new \InvalidArgumentException("Application directory not found: $appRoot");
        }
        $this->appRoot = $appRoot;
        $this->sourceDir = $sourceDir ?: $appRoot;
    }

    /**
     * Get a unique identifier for this app.
     *
     * @return string
     */
    public function getId()
    {
        return $this->getName() ?: $this->getPath() ?: 'default';
    }

    /**
     * @return string
     */
    protected function getPath()
    {
        return str_replace($this->sourceDir . '/' , '', $this->appRoot);
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        $config = $this->getConfig();

        return !empty($config['name']) ? $config['name'] : null;
    }

    /**
     * @return string
     */
    public function getRoot()
    {
        return $this->appRoot;
    }

    /**
     * Override the application config.
     *
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get the application's configuration, parsed from its YAML definition.
     *
     * @return array
     */
    public function getConfig()
    {
        if (!isset($this->config)) {
            $this->config = [];
            $file = $this->appRoot . '/' . CLI_APP_CONFIG_FILE;
            if (file_exists($file)) {
                try {
                    $parser = new Parser();
                    $config = (array) $parser->parse(file_get_contents($file));
                    $this->config = $this->normalizeConfig($config);
                }
                catch (ParseException $e) {
                    throw new InvalidConfigException(
                        "Parse error in file '$file': \n" . $e->getMessage()
                    );
                }
            }
        }

        return $this->config;
    }

    /**
     * Normalize an application's configuration.
     *
     * @param array $config
     *
     * @return array
     */
    protected function normalizeConfig(array $config)
    {
        // Backwards compatibility with old config format: `toolstack` is
        // changed to application `type` and `build`.`flavor`.
        if (isset($config['toolstack'])) {
            if (!strpos($config['toolstack'], ':')) {
                throw new InvalidConfigException("Invalid value for 'toolstack'");
            }
            list($config['type'], $config['build']['flavor']) = explode(':', $config['toolstack'], 2);
        }

        // The `web` section has changed to `web`.`locations`.
        if (isset($config['web']) && !isset($config['web']['locations'])) {
            $map = [
                'document_root' => 'root',
                'expires' => 'expires',
                'passthru' => 'passthru',
            ];
            foreach ($map as $key => $newKey) {
                if (array_key_exists($key, $config['web'])) {
                    $config['web']['locations']['/'][$newKey] = $config['web'][$key];
                }
            }
        }

        return $config;
    }

    /**
     * @return ToolstackInterface[]
     */
    public function getToolstacks()
    {
        return [
            new Toolstack\Drupal(),
            new Toolstack\Symfony(),
            new Toolstack\Composer(),
            new Toolstack\NodeJs(),
            new Toolstack\NoToolstack(),
        ];
    }

    /**
     * Get the toolstack for the application.
     *
     * @throws \Exception   If a specified toolstack is not found.
     *
     * @return ToolstackInterface|false
     */
    public function getToolstack()
    {
        $toolstackChoice = false;

        // For now, we reconstruct a toolstack string based on the 'type' and
        // 'build.flavor' config keys.
        $appConfig = $this->getConfig();
        if (isset($appConfig['type'])) {
            list($stack, ) = explode(':', $appConfig['type'], 2);
            $flavor = isset($appConfig['build']['flavor']) ? $appConfig['build']['flavor'] : 'default';

            // Toolstack classes for HHVM are the same as PHP.
            if ($stack === 'hhvm') {
                $stack = 'php';
            }

            $toolstackChoice = "$stack:$flavor";

            // Alias php:default to php:composer.
            if ($toolstackChoice === 'php:default') {
                $toolstackChoice = 'php:composer';
            }
        }

        foreach (self::getToolstacks() as $toolstack) {
            $key = $toolstack->getKey();
            if ((!$toolstackChoice && $toolstack->detect($this->getRoot()))
                || ($key && $toolstackChoice === $key)
            ) {
                return $toolstack;
            }
        }
        if ($toolstackChoice) {
            throw new \Exception("Toolstack not found: $toolstackChoice");
        }

        return false;
    }

    /**
     * Get a list of applications in a directory.
     *
     * @param string $directory The absolute path to a directory.
     *
     * @return static[]
     */
    public static function getApplications($directory)
    {
        // Finder can be extremely slow with a deep directory structure. The
        // search depth is limited to safeguard against this.
        $finder = new Finder();
        $finder->in($directory)
               ->ignoreDotFiles(false)
               ->name(CLI_APP_CONFIG_FILE)
               ->notPath('builds')
               ->notPath(CLI_LOCAL_DIR)
               ->depth('> 0')
               ->depth('< 5');

        $applications = [];
        if ($finder->count() == 0) {
            $applications[$directory] = new LocalApplication($directory, $directory);
        }
        else {
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            foreach ($finder as $file) {
                $appRoot = dirname($file->getRealPath());
                $applications[$appRoot] = new LocalApplication($appRoot, $directory);
            }
        }

        return $applications;
    }

    /**
     * Get the configured document root for the application, as a relative path.
     *
     * @return string
     */
    public function getDocumentRoot()
    {
        $config = $this->getConfig();

        // The default document root is '/public'. This is used if the root is
        // not set, if it is empty, or if it is set to '/'.
        $documentRoot = '/public';
        if (!empty($appConfig['web']['locations']['/']['root']) && $appConfig['web']['locations']['/']['root'] !== '/') {
            $documentRoot = $appConfig['web']['locations']['/']['root'];
        }

        return ltrim($documentRoot, '/');
    }

    /**
     * Check whether the whole app should be moved into the document root.
     *
     * @return string
     */
    public function shouldMoveToRoot()
    {
        $config = $this->getConfig();

        if (isset($config['move_to_root']) && $config['move_to_root'] === true) {
            return true;
        }

        return $this->getDocumentRoot() === 'public' && !is_dir($this->getRoot() . '/public');
    }
}
