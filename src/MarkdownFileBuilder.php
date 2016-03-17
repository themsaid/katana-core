<?php

namespace Katana;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Symfony\Component\Finder\SplFileInfo;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Factory;

class MarkdownFileBuilder
{
    protected $filesystem;
    protected $viewFactory;
    protected $file;
    protected $data;

    /**
     * The body of the file.
     *
     * @var string
     */
    protected $fileContent;

    /**
     * The YAML meta of the file.
     *
     * @var array
     */
    protected $fileYAML;

    /**
     * The BladeCompiler instance.
     *
     * @var BladeCompiler
     */
    protected $bladeCompiler;

    /**
     * The CompilerEngine instance.
     *
     * @var CompilerEngine
     */
    protected $engine;

    /**
     * The path to the cached compilation.
     *
     * @var string
     */
    protected $cached;

    /**
     * MarkdownFileBuilder constructor.
     *
     * @param Filesystem $filesystem
     * @param Factory $viewFactory
     */
    public function __construct(Filesystem $filesystem, Factory $viewFactory, SplFileInfo $file, array $data)
    {
        $this->filesystem = $filesystem;
        $this->viewFactory = $viewFactory;
        $this->file = $file;
        $this->data = $data;

        $parsed = Markdown::parseWithYAML($this->file->getContents());

        $this->fileContent = $parsed[0];

        $this->fileYAML = $parsed[1];

        $this->cached = KATANA_CACHE_DIR.'/'.sha1($this->file->getBasename()).'.php';

        $this->bladeCompiler = $this->getBladeCompiler();

        $this->engine = $this->getEngine();
    }

    /**
     * Get the evaluated contents of the file.
     */
    public function render()
    {
        $viewContent = $this->buildBladeViewContent();

        if ($this->isExpired()) {
            $this->filesystem->put($this->cached, $this->bladeCompiler->compileString($viewContent));
        }

        $data = $this->getViewData();

        return $this->engine->get($this->cached, $data);
    }

    /**
     * Build the content of the imaginary blade view.
     *
     * @return string
     */
    private function buildBladeViewContent()
    {
        $sections = '';

        foreach ($this->fileYAML as $name => $value) {
            $sections .= "@section('$name', '$value')\n\r";
        }

        return
            "@extends('{$this->fileYAML['extends']}')
            $sections
            @section('{$this->fileYAML['sectionName']}')

            {$this->fileContent}

            @stop";
    }

    /**
     * Return the BladeCompiler from the view factory.
     *
     * @return BladeCompiler
     */
    private function getBladeCompiler()
    {
        return $this->viewFactory->getEngineResolver()->resolve('blade')->getCompiler();
    }

    /**
     * Return the Compiler Engine from the view factory.
     *
     * @return CompilerEngine
     */
    private function getEngine()
    {
        return $this->viewFactory->getEngineFromPath('blade.php');
    }

    /**
     * Get variables to be passed to the view.
     *
     * @return array
     */
    private function getViewData()
    {
        $data = array_merge($this->viewFactory->getShared(), $this->data);

        foreach ($data as $key => $value) {
            if ($value instanceof Renderable) {
                $data[$key] = $value->render();
            }
        }

        return $data;
    }

    /**
     * Determine if the view at the given path is expired.
     *
     * @return bool
     */
    private function isExpired()
    {
        if (! $this->filesystem->exists($this->cached)) {
            return true;
        }

        $lastModified = $this->filesystem->lastModified($this->file->getPath());

        return $lastModified >= $this->filesystem->lastModified($this->cached);
    }
}