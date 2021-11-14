<?php


namespace wenbinye\tars\call;


use kuiper\helper\Text;
use function Composer\Autoload\includeFile;

class ClassLoader
{
    /**
     * @var string
     */
    private $namespace;

    /**
     * @var string
     */
    private $path;

    /**
     * ClassLoader constructor.
     * @param string $namespace
     * @param string $path
     */
    public function __construct(string $namespace, string $path)
    {
        $this->namespace = $namespace;
        $this->path = $path;
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'loadClass']);
    }

    public function loadClass(string $class): bool
    {
        if (Text::startsWith($class, $this->namespace)) {
            $file = $this->path . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($this->namespace))) . ".php";
            if (file_exists($file)) {
                includeFile($file);

                return true;
            }
        }
        return false;
    }

    public function getPath(string $app, string $server, string $md5 = null): string
    {
        return rtrim(implode(DIRECTORY_SEPARATOR, [$this->path, $app, $server, $this->remap($md5)]), DIRECTORY_SEPARATOR);
    }

    public function getNamespace(string $app, string $server, string $md5 = null): string
    {
        return rtrim(implode("\\", [$this->namespace, $app, $server, $this->remap($md5)]), '\\');
    }

    private function remap(?string $md5): ?string
    {
        return isset($md5) ? strtr($md5, '0123456789', 'hijklfnopqr') : null;
    }
}