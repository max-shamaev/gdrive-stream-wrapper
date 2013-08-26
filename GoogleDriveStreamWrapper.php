<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Google drive stream wrapper 
 */
class GoogleDriveStreamWrapper 
{

    /**
     * Directory separator 
     */
    const DS = '/';

    /**
     * Wrapper scheme
     */
	const SCHEME = 'gdrive';

	const TYPE_ANY       = 'any';
    const TYPE_DIRECTORY = 'dir';
    const TYPE_FILE      = 'file';

    // {{{ Service

    /**
     * Google drive service object
     * 
     * @var   \Google_DriveService
     */
    protected static $service;

    /**
     * Google apps domain
     *
     * @var   string
     */
    protected static $domain;

    /**
     * MIME types
     *
     * @var   array
     */
    protected static $mimes = array();

    /**
     * Set Google drive service object
     * 
	 * @param \Google_DriveService $service Google drive service object
     *  
     * @return void
     */
    public static function setSrvice(\Google_DriveService $service)
    {
        static::$service = $service;
    }

    /**
     * Set Google apps domain
     *
     * @param string $domain Domain
     *
     * @return void
     */
    public static function setDomain($domain)
    {
        static::$domain = $domain;
    }

    /**
     * Set MIME type for file
     *
     * @param string $path File path
     * @param string $mime MIME type
     *
     * @return void
     */
    public static function setMimetype($path, $mime)
    {
        static::$mimes[$path] = $mime;
    }

    /**
     * Register wrapper
     *
     * @return void
     */
	public static function registerWrapper()
	{
		stream_wrapper_register(static::SCHEME, get_called_class());
	}

    // }}}

    // {{{ Initialization

    /**
     * Constructor
     *
     * @return void
     * @throw  \Exception
     */
    public function __construct()
    {
        if (!static::$service) {
            throw new \Exception('Sevice did not set!');
        }
    }

    // }}}

    // {{{ Service common routines

    /**
     * Google drive root file (cache)
     *
     * @var   \Google_DriveFile
     */
    protected $root;

    /**
     * Get Google drive root file
     *
     * @return \Google_DriveFile
     */
    protected function getRoot()
    {
        if (!isset($this->root)) {
            $this->root = static::$service->files->get(static::$service->about->get()->getRootFolderId());
        }

        return $this->root;
    }

    /**
     * Check - specified file is file or directory
     *
     * @param \Google_DriveFile $file File
     *
     * @return boolean
     */
    protected function isDir(\Google_DriveFile $file)
    {
        return 'application/vnd.google-apps.folder' == $file->getMimetype();
    }

    /**
     * Converty full path to short path
     *
     * @param string $path Full path
     *
     * @return string
     */
    protected function convertPathToFS($path)
    {
        return substr($path, 8);
    }

    /**
     * Converty short path to full path
     *
     * @param string $path Short path
     *
     * @return string
     */
    protected function convertPathToURL($path)
    {
        return static::SCHEME . ':/' . $path;
    }

    /**
     * Get item by path
     *
     * @param string $path Short path
     * @param string $type Item type OPTIONAL
     *
     * @return \Google_DriveFile
     */
    protected function getItemByPath($path, $type = self::TYPE_ANY)
    {
        $dir = $this->getRoot();
        $parts = explode(static::DS, $path);
        array_shift($parts);
        foreach ($parts as $part) {
            $found = false;
            foreach ($this->getSubitems($dir, $type) as $item) {
                if ($item->getTitle() == $part) {
                    $found = $item;
                }
            }

            $dir = $found ?: null;
            if (!$dir) {
                break;
            }
        }

        return $dir;
    }

    /**
     * Get subitems by file
     *
     * @param \Google_DriveFile $file File
     * @param string            $type Item type OPTIONAL
     *
     * @return \Google_DriveFile
     */
    protected function getSubitems(\Google_DriveFile $file, $type = self::TYPE_ANY)
    {
		$q = '"' . $file->getId() . '" in parents';
		if (static::TYPE_DIRECTORY == $type) {
			$q .= ' and mimeType = "application/vnd.google-apps.folder"';

		} elseif (static::TYPE_FILE == $type) {
            $q .= ' and mimeType != "application/vnd.google-apps.folder"';
		}

        return static::$service
            ->files
            ->listFiles(array('q' => $q))
            ->getItems();
    }

    // }}}

    // {{{ StreamWrapper : Directories

    /**
     * Current directory
     *
     * @var   \Google_DriveFile
     */
    protected $dir;

    /**
     * Current directory items
     *
     * @var   array
     */
    protected $dirItems;

    /**
     * Current directory path
     *
     * @var   string
     */
    protected $dirPath;

    /**
     * opendir() wrapper
     *
     * @param string  $path    Directory patrh
     * @param integer $options Options NOT SUPPORT
     *
     * @return boolean
     */
    public function dir_opendir($path, $options)
    {
        if ($this->dir) {
            $this->dir_closedir();
        }

        $this->dirPath = $path;
        $this->dir = $this->getItemByPath($this->convertPathToFS($path), static::TYPE_DIRECTORY);

        return isset($this->dir);
    }

    /**
     * readdir() wrapper
     *
     * @return string
     */
    public function dir_readdir()
    {
        if (!isset($this->dirItems)) {
            $this->dirItems = $this->getSubitems($this->dir);
        }

        $item = each($this->dirItems);

        return ($item && !empty($item[1]))
            ? $this->dirPath . static::DS . $item[1]->getTitle()
            : null;
    }

    /**
     * rewinddir() wrapper
     *
     * @return boolean
     */
    public function dir_rewinddir()
    {
        $this->dirItems = null;

        return true;
    }

    /**
     * closedir() wrapper
     *
     * @return boolean
     */
    public function dir_closedir()
    {
        $this->dir = null;
        $this->dirItems = null;

        return true;
    }

    /**
     * mkdir() wrapper
     *
     * @param string  $path    Directory patrh
     * @param integer $mode    Permission mode
     * @param integer $options Options
     *
     * @return boolean
     */
    public function mkdir($path, $mode, $options)
    {
        $dir = $this->getRoot();
        $parts = explode(static::DS, $this->convertPathToFS($path));
        array_shift($parts);
		$length = count($parts);
		$lastPath = array();
        foreach ($parts as $i => $part) {
            $found = false;
            foreach ($this->getSubitems($dir, static::TYPE_DIRECTORY) as $item) {
                if ($item->getTitle() == $part) {
                    $found = $item;
                }
            }

			$lastPath[] = $part;

            if ($found) {
                $dir = $found;

            } elseif ($length == $i + 1 || $options & STREAM_MKDIR_RECURSIVE) {
                $ref = new \Google_ParentReference;
                $ref->setId($dir->getId());
                $dir = new \Google_DriveFile();
                $dir->setTitle($part);
                $dir->setMimeType('application/vnd.google-apps.folder');
                $dir->setParents(array($ref));
                $dir = static::$service->files->insert(
                    $dir,
                    array(
                        'mimeType' => 'application/vnd.google-apps.folder',
                    )
                );

				chmod($this->convertPathToURL('/' . implode(static::DS, $lastPath)), $mode);
            }
        }

        return isset($dir);
    }

    /**
     * rmdir() wrapper
     *
     * @param string  $path    Directory patrh
     * @param integer $options Options NOT SUPPORT
     *
     * @return boolean
     */
    public function rmdir($path, $options)
    {
        $result = false;

        if (file_exists($path) && is_dir($path)) {
            $res = opendir($path);
            $count = 0;
            while (readdir($res)) {
                $count++;
            }
            closedir($res);
            if (0 == $count) {
                static::$service->files->delete($this->getItemByPath($this->convertPathToFS($path), static::TYPE_DIRECTORY)->getId());
                $result = true;
            }
        }

        return $result;
    }

    // }}}

    // {{{ StreamWrapper : File

    /**
     * Current file
     *
     * @var   \Google_DriveFile
     */
    protected $file;

    /**
     * Current file path
     *
     * @var   string
     */
    protected $filePath;

    /**
     * Current file position
     *
     * @var   integer
     */
    protected $filePosition = 0;

    /**
     * Current file body (cache)
     *
     * @var   string
     */
    protected $fileBody;

    /**
     * Current file mode
     *
     * @var   string
     */
    protected $fileMode;

    /**
     * fopen() wrapper
     *
     * @param string  $path    Directory patrh
     * @param string  $mode    File open mode
     * @param integer $options Options NOT SUPPORT
     *
     * @return boolean
     */
    public function stream_open($path, $mode, $options)
    {
        $this->filePosition = 0;

        $file = $this->getItemByPath($this->convertPathToFS($path));
        if ($file && 'x' == substr($mode, 0, 1)) {
            $file = null;

        } elseif (!$file && 'r' != substr($mode, 0, 1)) {
            $dir = $this->getItemByPath(dirname($this->convertPathToFS($path)), static::TYPE_DIRECTORY);
            if ($dir) {
                $file = new \Google_DriveFile();
                $file->setTitle(basename($this->convertPathToFS($path)));
                $file->setMimeType($this->detectMimetype($path));

                $ref = new \Google_ParentReference;
                $ref->setId($dir->getId());
                $file->setParents(array($ref));
            }
        }

        if ($file) {
            $this->file = $file;
            $this->filePath = $path;
            $this->fileMode = $mode;
        }

        return isset($file);
    }

    /**
     * fread() wrapper
     *
     * @param integer $count Chunk lenght
     *
     * @return string
     */
    public function stream_read($count)
    {
        if (0 < $count) {
            $result = substr($this->downloadFile(), $this->filePosition, $count);
            $this->filePosition += $count;
        }

        return $result;
    }

    /**
     * fwrite() wrapper
     *
     * @param string $data Data
     *
     * @return integer
     */
    public function stream_write($data)
    {
        $size = 0;

        if ('r' != substr($this->fileMode, 0, 1)) {
            $this->downloadFile();

            if ('c' == substr($this->fileMode, 0, 1)) {
                $this->fileBody = substr($this->fileBody, 0, $this->filePosition) . $data . substr($this->fileBody, $this->filePosition + strlen($data));

            } else {
                $this->fileBody .= $data;
            }

            $this->filePosition += strlen($data);

            $this->file = $this->file->getId()
                ? static::$service->files->update($this->file->getId(), $this->file, array('data' => $this->fileBody, 'mimeType' => $this->detectMimetype($this->filePath)))
                : static::$service->files->insert($this->file, array('data' => $this->fileBody, 'mimeType' => $this->detectMimetype($this->filePath)));

            $size = strlen($data);
        }

        return $size;
    }

    /**
     * feof() wrapper
     *
     * @return b`oolean
     */
    public function stream_eof()
    {
        return $this->filePosition >= $this->file->getFilesize();
    }

    /**
     * fseek() wrapper
     *
     * @param integer $offset Offset
     * @param integer $whence Whence OPTIONAL
     *
     * @return boolean
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        switch ($whence) {
            case SEEK_SET:
                $this->filePosition = $offset;
                break;

            case SEEK_CUR:
				$this->filePosition += $whence;
                break;

            case SEEK_END:
                $this->filePosition = $this->file->getFilesize() + $whence;
                break;

            default:
        }

		$this->filePosition = max(0, min($this->filePosition, $this->file->getFilesize()));

        return true;
    }

    /**
     * ftruncate() wrapper
     *
     * @param integer $new_size New size
     *
     * @return boolean
     */
    public function stream_truncate($new_size)
    {
        $this->downloadFile();

        $this->fileBody = substr($this->fileBody, 0, $new_size);
        $this->filePosition = min($this->filePosition, $new_size);

        $this->file = $this->file->getId()
            ? static::$service->files->update($this->file->getId(), $this->file, array('data' => $this->fileBody))
            : static::$service->files->insert($this->file, array('data' => $this->fileBody, 'mimeType' => $this->detectMimetype($this->filePath)));

        return true;
    }

    /**
     * fflush() wrapper
     *
     * @return boolean
     */
    public function stream_flush()
    {
        return true;
    }

    /**
     * ftell() wrapper
     *
     * @return integer
     */
    public function stream_tell()
    {
        return $this->filePosition;
    }

    /**
     * fstat() wrapper
     *
     * @return array
     */
    public function stream_stat()
    {
        return $this->getStat($this->file);
    }

    /**
     * flock() wrapper
     * NOT SUPPORT
     *
     * @param integer $operation Operation
     *
     * @return boolean
     */
    public function stream_lock($operation)
    {
        return false;
    }

    /**
     * fclose() wrapper
     *
     * @return boolean
     */
    public function close()
    {
        $this->file = null;
        $this->filePath = null;
        $this->fileBody = null;

        return true;
    }

    /**
     * unlink() wrapper
     *
     * @param string $path Path
     *
     * @return boolean
     */
    public function unlink($path)
    {
        $result = false;

        $file = $this->getItemByPath($this->convertPathToFS($path));

        if ($file && is_file($file)) {
            static::$service->files->delete($file->getid());
            $result = true;
        }

        return $result;
    }

    /**
     * Download file
     *
     * @return string
     */
    protected function downloadFile()
    {
        if (!isset($this->fileBody)) {
            if (in_array(substr($this->fileMode, 0, 1), array('r', 'c', 'a'))) {
                $request = new \Google_HttpRequest($this->file->getDownloadUrl(), 'GET', null, null);
                $httpRequest = \Google_Client::$io->authenticatedRequest($request);
                if ($httpRequest->getResponseHttpCode() == 200) {
                    $this->fileBody = $httpRequest->getResponseBody();
                }

                if ('a' == substr($this->fileMode, 0, 1)) {
                    $this->filePosition = strlen($this->fileBody);
                }

            } else {
                $this->fileBody = '';
            }
        }

        return $this->fileBody;
    }

    /**
     * Detect MIME type
     *
     * @param string $path Path
     *
     * @return string
     */
	protected function detectMimetype($path)
	{
		return empty(static::$mimes[$path]) ? 'text/plain' : static::$mimes[$path];
	}

    // }}}

    // {{{ StreamWrapper : Common operations

    /**
     * rename() wrapper
     *
     * @param string $path_from Path (from)
     * @param string $path_to   Path (to)
     *
     * @return boolean
     */
    public function rename($path_from, $path_to)
    {
        $result = false;

        if (file_exists($path_from) && is_dir($path_from)) {
            $from = $this->getItemByPath($this->convertPathToFS($path_from));

            $parentTo = dirname($this->convertPathToFS($path_to));
            mkdir($this->convertPathToURL($parentTo));
            $from->setTitle(basename($this->convertPathToFS($path_to)));
            $ref = new \Google_ParentReference;
            $parentToFile = $this->getItemByPath($parentTo);
            $ref->setId($parentToFile->getId());
            $from->setParents(array($ref));
            static::$service->files->update($from->getId(), $from);
            $result = true;
        }

        return $result;
    }

    /**
     * stat() wrapper
     *
     * @param string  $path  Path
     * @param integer $flags Flags NOT SUPPORT
     *
     * @return array
     */
    public function url_stat($path, $flags)
    {
        $file = $this->getItemByPath($this->convertPathToFS($path));

        return $file ? $this->getStat($file) : false;
    }

    /**
     * touch() / chmod() / chown() / chgrp() wrapper
     *
     * @param string  $path   Path
     * @param integer $option Option PARTYALLY SUPPORT
     * @param mixed   $value  Value PARTYALLY SUPPORT
     *
     * @return boolean
     */
    public function stream_metadata($path, $option, $value)
    {
        $result = false;

        $file = $this->getItemByPath($this->convertPathToFS($path));
        if ($file) {
            switch ($option) {
                case STREAM_META_TOUCH:
                    static::$service->files->update($file->getId(), $file, array('setModifiedDate' => true, 'updateViewedDate' => true));
					$result = true;
                    break;

                case STREAM_META_OWNER_NAME:
                    // Not support
                    break;

                case STREAM_META_OWNER:
                    // Not support
                    break;

                case STREAM_META_GROUP_NAME:
                    // Not support
                    break;

                case STREAM_META_GROUP:
                    // Not support
                    break;

                case STREAM_META_ACCESS:
                    // Not support
                    /*
                    $permissions = static::$service->permissions->listPermissions($file->getId())->getItems()
                    if ($value & 0006) {
                        $found = false;
                        foreach ($permissions as $permisssion) {
                            if ('writer' == $permisssion->getRole() && 'anyone' == $permisssion->getType()) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                        }

                    } elseif ($value & 0004) {
                        $found = false;
                        foreach ($permissions as $permisssion) {
                            if (in_array($permisssion->getRole(), array('reader', 'writer')) && 'anyone' == $permisssion->getType()) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                        }
                    }

                    $permissions = static::$service->permissions->listPermissions($file->getId())->getItems()
                    if ($value & 0060) {
                        $found = false;
                        foreach ($permissions as $permisssion) {
                            if ('writer' == $permisssion->getRole() && 'domain' == $permisssion->getType()) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                        }

                    } elseif ($value & 0040) {
                        $found = false;
                        foreach ($permissions as $permisssion) {
                            if (in_array($permisssion->getRole(), array('reader', 'writer')) && 'domain' == $permisssion->getType()) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                        }
                    }


                    */


                default:
            }
        }

        return $result;
    }

    /**
     * stream_select() wrapper
     * NOT SUPPORT
     *
     * @param integer $cast_as Cast flag
     *
     * @return resource
     */
	public function stream_cast($cast_as)
	{
		return false;
	}

    /**
     * stream_set_blocking() / stream_set_timeout() / stream_set_write_buffer() wrapper
     * NOT SUPPORT
     *
     * @param integer $option Option
     * @param integer $arg1   Argument 1
     * @param integer $arg2   Argument 2
     *
     * @return boolean
     */
	public function stream_set_option($option, $arg1, $arg2)
	{
		return false;
	}

    /**
     * Get file statistics
     *
     * @param \Google_DriveFile $file File
     *
     * @return array
     */
    protected function getStat(\Google_DriveFile $file)
    {
        $result = array(
            0,
            0,
            $this->isDir($file) ? 0040600 : 0100600,
            0,
            current($file->getOwnerNames()),
            0,
            0,
            $file->getFileSize(),
            $file->getLastViewedByMeDate() ? strtotime($file->getLastViewedByMeDate()) : time(),
            $file->getModifiedDate() ? strtotime($file->getModifiedDate()) : time(),
            $file->getCreatedDate() ? strtotime($file->getCreatedDate()) : time(),
            -1,
            -1,
        );

        $result['dev']     = $result[0];
        $result['ino']     = $result[1];
        $result['mode']    = $result[2];
        $result['nlink']   = $result[3];
        $result['uid']     = $result[4];
        $result['gid']     = $result[5];
        $result['rdev']    = $result[6];
        $result['size']    = $result[7];
        $result['atime']   = $result[8];
        $result['mtime']   = $result[9];
        $result['ctime']   = $result[10];
        $result['blksize'] = $result[11];
        $result['blocks']  = $result[12];

        return $result;
    }

    // }}}

}
