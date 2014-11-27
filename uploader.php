<?php
/** This file is part of load.link (https://github.com/deuiore/load.link).
 * View the LICENSE file for full license information.
 **/

class Uploader
{
    protected $conf;
    protected $tmp_path;
    protected $name;
    protected $path;
    protected $mime;
    protected $uid;

    public function __construct($name, $tmp_path)
    {
        $this->conf = Config::get()->getSection('link');

        $this->tmp_path = $tmp_path;

        $this->name = $name;
        $this->mime = Utils::detectMime($tmp_path);

        $this->path = $this->conf['upload_dir'] . $this->name;
        while (file_exists($this->path))
        {
            $this->path .= $this->conf['same_name_suffix'];
        }
    }

    public function upload()
    {
        if (move_uploaded_file($this->tmp_path, $this->path))
        {
            try
            {
                $uid = DB::get()->addLink(
                    $this->path, $this->name, $this->mime);
            }
            catch (Exception $e)
            {
                return FALSE;
            }

            $this->uid = $uid;
            return TRUE;
        }
    }

    public function getLink()
    {
        if (!$this->uid)
        {
            throw new Error(Error::FATAL,
                'UID not found.');
        }

        $ext = pathinfo($this->name, PATHINFO_EXTENSION);

        return Router::getURL() . $this->uid
            . (($ext && Config::get()->getValue('link', 'show_extension')) ?
                '.' . $ext : '');
    }
}