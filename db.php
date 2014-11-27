<?php
/** This file is part of load.link (https://github.com/deuiore/load.link).
 * View the LICENSE file for full license information.
 **/

class DB
{
    protected static $db;
    public static function get()
    {
        if (!self::$db)
        {
            self::$db = new DB();
        }
        return self::$db;
    }

    protected $dbh;
    protected $conf;

    protected function __construct()
    {
        $this->conf = Config::get()->getSection('database');

        if ($this->conf['type'] == 'sqlite')
        {
            $dsn = $this->conf['type'] . ':' . $this->conf['name'];
            $this->dbh = new PDO($dsn);
        }
        else
        {
            $dsn = $this->conf['type'] . ':dbname=' . $this->conf['name']
                . ';host=' . (($this->conf['host'] != '') ?
                    $this->conf['host'] : '127.0.0.1')
                . (($this->conf['port'] != '') ?
                ';port=' . $this->conf['port'] : '');

            $this->dbh = new PDO($dsn,
                $this->conf['username'], $this->conf['password']);
        }

        if (!$this->dbh)
        {
            $error = new Error(Error::FATAL,
                'Could not connect to database "' . $dsn . '".');
            $error->getPage()->render();
            exit();
        }

        $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getLink($uid)
    {
        $sth = $this->dbh->prepare('SELECT * FROM '
            . $this->conf['table_prefix'] . 'links'
            . ' WHERE uid = :uid');
        $sth->execute(array(':uid' => $uid));
        return $sth->fetch();
    }

    public function getLastNLinks($n)
    {
        if (!$n)
        {
            return array();
        }

        $sth = $this->dbh->prepare('SELECT * FROM '
            . $this->conf['table_prefix'] . 'links'
            . ' ORDER BY date DESC LIMIT :n');
        $sth->execute(array(':n' => $n));
        return $sth->fetchAll();
    }

    public function getAllLinks()
    {
        $sth = $this->dbh->prepare('SELECT * FROM '
            . $this->conf['table_prefix'] . 'links'
            . ' ORDER BY date DESC');
        $sth->execute();
        return $sth->fetchAll();
    }

    public function getThumbnail($uid)
    {
        $sth = $this->dbh->prepare('SELECT * FROM '
            . $this->conf['table_prefix'] . 'thumbnails'
            . ' WHERE uid = :uid');
        $sth->execute(array(':uid' => $uid));
        return $sth->fetch();
    }

    protected function generateUID()
    {
        $characters = Config::get()->getValue('link', 'characters');
        do
        {
            $id = '';
            for ($i = 0; $i < Config::get()->getValue('link', 'length'); $i++)
            {
               $id .= $characters[rand(0, strlen($characters) - 1)];
            }

            if (in_array($id, Router::getReservedRoutes()))
            {
                continue;
            }

            $sth = $this->dbh->prepare('SELECT uid FROM '
                . $this->conf['table_prefix'] . 'links'
                . ' WHERE uid = :id');
            $sth->execute(array(':id' => $id));
            if ($sth->rowCount() == 0)
            {
                break;
            }
        }
        while (1);

        return $id;
    }

    public function addLink($path, $name, $mime)
    {
        $uid = $this->generateUID();

        $ext = ($path) ? pathinfo($name, PATHINFO_EXTENSION) : '';

        $sth = $this->dbh->prepare('INSERT INTO '
            . $this->conf['table_prefix'] . 'links'
            . ' (uid, path, name, ext, mime)'
            . ' VALUES (:uid, :path, :name, :ext, :mime)');
        $sth->execute(array(
            ':uid'  => $uid,
            ':path' => $path,
            ':name' => $name,
            ':ext'  => $ext,
            ':mime' => $mime));

        $thumbnail = Utils::generateThumbnail($path, $mime);
        if ($thumbnail)
        {
            $sth = $this->dbh->prepare('INSERT INTO '
                . $this->conf['table_prefix'] . 'thumbnails'
                . ' (uid, data, width, height, mime)'
                . ' VALUES (:uid, :data, :width, :height, :mime)');
            $sth->execute(array(
                ':uid'  => $uid,
                ':data'  => $thumbnail['data'],
                ':width'  => $thumbnail['width'],
                ':height'  => $thumbnail['height'],
                ':mime' => $thumbnail['mime']));
        }

        return $uid;
    }

    public function delLink($uid)
    {
        $link = $this->getLink($uid);

        $sth = $this->dbh->prepare('DELETE FROM '
            . $this->conf['table_prefix'] . 'links'
            . ' WHERE uid = :uid');
        $sth->execute(array(
            ':uid' => $uid));

        if ($link['path'] != '' && file_exists($link['path']))
        {
            unlink($link['path']);
        }

        if ($this->getThumbnail($uid))
        {
            $sth = $this->dbh->prepare('DELETE FROM '
                . $this->conf['table_prefix'] . 'thumbnails'
                . ' WHERE uid = :uid');
            $sth->execute(array(
                ':uid' => $uid));
        }
    }

    public function pruneUnused()
    {
        $links = $this->getAllLinks();

        $pruned = 0;
        foreach ($links as $link)
        {
            if (!file_exists($link['path']))
            {
                $this->delLink($link['uid']);
                $pruned++;
            }
        }

        return $pruned;
    }

    public function getSession($token)
    {
        $sth = $this->dbh->prepare('SELECT * FROM '
            . $this->conf['table_prefix'] . 'sessions'
            . ' WHERE token = :token');
        $sth->execute(array(':token' => $token));
        return $sth->fetch();
    }

    public function addSession()
    {
        $token = hash('sha512', uniqid());

        $sth = $this->dbh->prepare('INSERT INTO '
            . $this->conf['table_prefix'] . 'sessions (token)'
            . ' VALUES (:token)');
        $sth->execute(array(
            ':token' => $token));

        return $token;
    }

    public function delSession($token)
    {
        $sth = $this->dbh->prepare('DELETE FROM '
            . $this->conf['table_prefix'] . 'sessions'
            . ' WHERE token = :token');
        $sth->execute(array(
            ':token' => $token));
    }

    public function delAllSessions()
    {
        $sth = $this->dbh->prepare('DELETE FROM '
            . $this->conf['table_prefix'] . 'sessions');
        $sth->execute();
    }

    public static function sqlite_create($filename)
    {
        if (file_exists($filename))
        {
            throw new Error(Error::FATAL,
                'Database "' . $filename . '" already exists.');
        }
        else
        {
            if (!new SQLite3($filename))
            {
                throw new Error(Error::FATAL,
                    'Could not create database "' . $filename . '".');
            }
        }
    }

    public function install()
    {
        try
        {
            if ($this->dbh->query('SELECT 1 FROM '
                . $this->conf['table_prefix'] . 'links'))
            {
                throw new Error(Error::FATAL, 'Already installed.');
            }
        }
        catch(Exception $e)
        {
            // Empty database, so this is actually a GO.
        };

        $this->dbh->query('
            CREATE TABLE ' . $this->conf['table_prefix'] . 'links
            (
                uid     VARCHAR('
                . Config::get()->getValue('link', 'length')
                . ') PRIMARY KEY,
                path    TEXT,
                name    VARCHAR(255),
                ext     VARCHAR(255),
                mime    VARCHAR(255),
                date    INTEGER DEFAULT CURRENT_TIMESTAMP
            );');

        $this->dbh->query('
            CREATE TABLE ' . $this->conf['table_prefix'] . 'thumbnails
            (
                uid     VARCHAR('
                . Config::get()->getValue('link', 'length')
                . ') PRIMARY KEY,
                data    BLOB,
                mime    VARCHAR(255),
                width   INTEGER,
                height  INTEGER
            );');

        $this->dbh->query('
            CREATE TABLE ' . $this->conf['table_prefix'] . 'sessions
            (
                token   VARCHAR(128) PRIMARY KEY
            );
        ');
    }
}