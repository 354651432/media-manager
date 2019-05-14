<?php

namespace Encore\Admin\Media;

use Encore\Admin\Exception\Handler;
use Encore\Admin\Extension;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Adapter\Local;

/**
 * Class MediaManager.
 */
class MediaManager extends Extension
{
    protected $name = 'media-manager';

    /**
     * @var string
     */
    protected $path = '/';

    protected $prefix;

    /**
     * @var \Illuminate\Filesystem\FilesystemAdapter
     */
    protected $storage;

    /**
     * @var array
     */
    protected $fileTypes = [
        'image' => 'png|jpg|jpeg|tmp|gif',
        'word' => 'doc|docx',
        'ppt' => 'ppt|pptx',
        'pdf' => 'pdf',
        'code' => 'php|js|java|python|ruby|go|c|cpp|sql|m|h|json|html|aspx',
        'zip' => 'zip|tar\.gz|rar|rpm',
        'txt' => 'txt|pac|log|md',
        'audio' => 'mp3|wav|flac|3pg|aa|aac|ape|au|m4a|mpc|ogg',
        'video' => 'mkv|rmvb|flv|mp4|avi|wmv|rm|asf|mpeg',
    ];

    /**
     * {@inheritdoc}
     */
    public static function boot()
    {
        if (!parent::boot()) {
            return;
        }

        static::registerRoutes();
    }

    /**
     * Register routes for laravel-admin.
     *
     * @return void
     */
    protected static function registerRoutes()
    {
        parent::routes(function ($router) {
            /* @var \Illuminate\Routing\Router $router */
            $controller = static::config("controller", 'Encore\Admin\Media\MediaController');
            $basepath = static::config("basepath", 'media');

            $router->get($basepath, $controller . '@index')->name('media-index');
            $router->get($basepath . '/download', $controller . '@download')->name('media-download');
            $router->delete($basepath . '/delete', $controller . '@delete')->name('media-delete');
            $router->put($basepath . '/move', $controller . '@move')->name('media-move');
            $router->post($basepath . '/upload', $controller . '@upload')->name('media-upload');
            $router->post($basepath . '/folder', $controller . '@newFolder')->name('media-new-folder');
        });
    }

    /**
     * {@inheritdoc}
     */
    public static function import()
    {
        parent::createMenu('Media manager', 'media', 'fa-file');

        parent::createPermission('Media manager', 'ext.media-manager', 'media*');
    }


    /**
     * MediaManager constructor.
     *
     * @param string $path
     * @param string $prefix
     */
    public function __construct($path = '/', $prefix = '')
    {
        $path = str_start($path, '/');
        $prefix = str_start($prefix, '/');

        $this->path = $prefix . $path;

        $this->prefix = $prefix;

        $disk = static::config('disk');

        $this->storage = Storage::disk($disk);

        if (!$this->storage->getDriver()->getAdapter() instanceof Local) {
            Handler::error('Error', '[laravel-admin-ext/media-manager] only works for local storage.');
        }
    }

    public function ls()
    {
        $path = $this->getFullPath($this->path);
        if (!file_exists($path)) {
            mkdir($path);
            return [];
        }

        $files = $this->storage->files($this->path);

        $directories = $this->storage->directories($this->path);

        return $this->formatDirectories($directories)
            ->merge($this->formatFiles($files))
            ->sort(function ($item) {
                return $item['name'];
            })->all();
    }

    /**
     * Get full path for a giving fiel path.
     *
     * @param string $path
     *
     * @return string
     */
    protected function getFullPath($path)
    {
        return $this->storage->getDriver()->getAdapter()->applyPathPrefix($path);
    }

    public function download()
    {
        $fullPath = $this->getFullPath($this->path);

        if (File::isFile($fullPath)) {
            return response()->download($fullPath);
        }

        return response('', 404);
    }

    public function delete($path)
    {

        $paths = is_array($path) ? $path : func_get_args();

        foreach ($paths as $path) {
            $path = $this->prefix . $path;
            $fullPath = $this->getFullPath($path);

            if (is_file($fullPath)) {
                $this->storage->delete($path);
            } else {
                $this->storage->deleteDirectory($path);
            }
        }

        return true;
    }

    public function move($new)
    {
        return $this->storage->move($this->path, $new);
    }

    /**
     * @param UploadedFile[] $files
     * @return mixed
     */
    public function upload($files = [])
    {
        foreach ($files as $file) {
            $this->storage->putFileAs($this->path, $file, $file->getClientOriginalName());
        }

        return true;
    }

    public function newFolder($name)
    {
        $path = rtrim($this->path, '/') . '/' . trim($name, '/');

        return $this->storage->makeDirectory($path);
    }

    /**
     * @return array
     */
    public function urls()
    {
        return [
            'path' => $this->path($this->path),
            'index' => route('media-index'),
            'move' => route('media-move'),
            'delete' => route('media-delete'),
            'upload' => route('media-upload'),
            'new-folder' => route('media-new-folder'),
        ];
    }

    public function formatFiles($files = [])
    {
        if (!static::config("show_hide")) {
            $files = $this->skipHide($files);
        }

        $files = array_map(function ($srcfile) {
            $file = $this->path($srcfile);
            return [
                'download' => route('media-download', compact('file')),
                'icon' => '',
                'name' => $srcfile,
                'basename' => $this->basename($file),
                'preview' => $this->getFilePreview($this->prefix . $file),
                'isDir' => false,
                'size' => $this->getFilesize($this->prefix . $file),
                'link' => route('media-download', compact('file')),
                'url' => $this->storage->url($file),
                'time' => $this->getFileChangeTime($this->prefix . $file),
            ];
        }, $files);

        return collect($files);
    }

    public function formatDirectories($dirs = [])
    {
        if (!static::config("show_hide")) {
            $dirs = $this->skipHide($dirs);
        }

        $url = route('media-index', ['path' => '__path__', 'view' => request('view')]);

        $preview = "<a href=\"$url\"><span class=\"file-icon text-aqua\"><i class=\"fa fa-folder\"></i></span></a>";

        $dirs = array_map(function ($dir) use ($preview) {
            $path = $this->path($dir);
            return [
                'download' => '',
                'icon' => '',
                'name' => $dir,
                'basename' => $this->basename($dir),
                'preview' => str_replace('__path__', $path, $preview),
                'isDir' => true,
                'size' => '',
                'link' => route('media-index',
                    ['path' => $path, 'view' => request('view')]),
                'url' => $this->storage->url($dir),
                'time' => $this->getFileChangeTime($dir),
            ];
        }, $dirs);

        return collect($dirs);
    }

    private function path($path)
    {
        $path = str_start($path, '/');
        if (starts_with($path, $this->prefix)) {
            return mb_substr($path, mb_strlen($this->prefix));
        }

        return $path;
    }

    public function navigation()
    {
        $folders = explode('/', $this->path);

        $folders = array_filter($folders);

        if ($this->prefix) {
            array_shift($folders);
        }

        $path = '';

        $navigation = [];

        foreach ($folders as $folder) {
            $path = rtrim($path, '/') . '/' . $folder;

            $navigation[] = [
                'name' => $folder,
                'url' => route('media-index', ['path' => $path]),
            ];
        }

        return $navigation;
    }

    public function getFilePreview($file)
    {
        switch ($this->detectFileType($file)) {
            case 'image':

                if ($this->storage->getDriver()->getConfig()->has('url')) {
                    $url = $this->storage->url($file);
                    $preview = "<span class=\"file-icon has-img\"><img src=\"$url\" alt=\"Attachment\"></span>";
                } else {
                    $preview = '<span class="file-icon"><i class="fa fa-file-image-o"></i></span>';
                }
                break;

            case 'pdf':
                $preview = '<span class="file-icon"><i class="fa fa-file-pdf-o"></i></span>';
                break;

            case 'zip':
                $preview = '<span class="file-icon"><i class="fa fa-file-zip-o"></i></span>';
                break;

            case 'word':
                $preview = '<span class="file-icon"><i class="fa fa-file-word-o"></i></span>';
                break;

            case 'ppt':
                $preview = '<span class="file-icon"><i class="fa fa-file-powerpoint-o"></i></span>';
                break;

            case 'xls':
                $preview = '<span class="file-icon"><i class="fa fa-file-excel-o"></i></span>';
                break;

            case 'txt':
                $preview = '<span class="file-icon"><i class="fa fa-file-text-o"></i></span>';
                break;

            case 'code':
                $preview = '<span class="file-icon"><i class="fa fa-code"></i></span>';
                break;

            default:
                $preview = '<span class="file-icon"><i class="fa fa-file"></i></span>';
        }

        return $preview;
    }

    protected function detectFileType($file)
    {
        $extension = File::extension($file);

        foreach ($this->fileTypes as $type => $regex) {
            if (preg_match("/^($regex)$/i", $extension) !== 0) {
                return $type;
            }
        }

        return false;
    }

    public function getFilesize($file)
    {
        $bytes = filesize($this->getFullPath($file));

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getFileChangeTime($file)
    {
        $time = filectime($this->getFullPath($file));

        return date('Y-m-d H:i:s', $time);
    }

    public function basename($path)
    {
        return preg_replace('/^.+[\\\\\\/]/', '', $path);
    }

    public function header()
    {
        return static::config("header", "Media Manager");
    }

    private function skipHide($dirs)
    {
        return array_filter($dirs, function ($dir) {
            return isset($dir[0]) && $dir[0] != '.';
        });
    }
}
