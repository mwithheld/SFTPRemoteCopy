<?php
//echo print_r(getenv(), true);
//phpinfo();
//die();
error_reporting(-1);
//ini_set('error_reporting', E_ALL);
ini_set('display_errors', 'On');
ini_set('max_input_time', PHP_INT_MAX);
set_time_limit(10);
date_default_timezone_set('America/Vancouver');
//@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) {
    @ob_end_flush();
}
ob_implicit_flush(1);

define('BRNL', "<br />\n");
define('HR', "<hr>");

$path = dirname(__FILE__) . '/lib/phpseclib';
set_include_path(get_include_path() . PATH_SEPARATOR . $path);

echo 'Incude path=' . get_include_path() . BRNL;

//include('Net/SSH2.php');
require_once('Net/SFTP.php');
define('NET_SFTP_LOGGING', NET_SFTP_LOG_SIMPLE);

require_once('config.php');

require_once('lib/logger/Logger.php');

require_once('lib/db/mysqldatabase.php');
require_once('lib/db/mysqlresultset.php');

//========

echo 'Libraries found and constants set' . BRNL;

class MarkZilla {
    
    //Object holders
    public $sftp = NULL;
    protected $db = NULL;
    protected $log = NULL;

    //keeps track of directories we've already processed (loaded from the DB into this var)
    protected $db_processed = '';
    
    function __construct($config) {
        $this->importProperties($config);
            
        $this->log_path = dirname(__FILE__)."/temp/$this->log_path";
        $this->log = new Logger($this->log_path); 
        $this->setupDatabase();
        $this->db_processed = $this->getProcessed();
    }

    function importProperties($object) {   
        foreach (get_object_vars($object) as $key => $value) {
            $this->$key = $value;
        }
    }  
    
    function setupDatabase() {
        // get the MySqlDatabase instance
        $this->db = MySqlDatabase::getInstance();
        try {
            $conn = $this->db->connect($this->db_host, $this->db_user, $this->db_pw, $this->db_databaseName);
        } catch (Exception $e) {
            die(__FUNCTION__.'::'.$e->getMessage());
        }
    }
    
    function showPath() {
        $this->output('Directory=' . $this->sftp->pwd());
    }

    function dbLog($msg) {
        $msg = mysql_escape_string(filter_var($msg, FILTER_SANITIZE_STRING));
        $query = "REPLACE INTO sync (location) VALUES ('$msg')";
        $lastId = $this->db->insert($query);
        return $lastId;
    }
    
    function getProcessed() {
        $query = 'SELECT location from sync';
        $result = array();
        foreach ($this->db->iterate($query) as $row) {
            $result[] = $row->location;
        }
        return $result;
    }
    
    
    function output($msg) {
        //echo $msg . BRNL;
        $this->log->logWrite($msg);
        //fflush();
    }

    function output_error($msg) {
        $this->output("<font color=\"red\">$msg</font>");
    }

    function pre($msg) {
        echo HR . '<PRE>' . print_r($msg, true) . '</PRE>' . HR;
    }

    /**
     * 
     * @param type $localDir absolute local pathname from the root localdir
     * @return type
     * @throws Exception
     */
    function ensure_localdir_exists($localDir) {
        $debugThis = false;
        if ($debugThis)
            $this->output(__FUNCTION__ . " looking for local folder $localDir");
        $localDir = trim($localDir);
        if (!is_dir($localDir)) {
            $dirArr = explode(DIRECTORY_SEPARATOR, $localDir);
            $dirname = array_pop($dirArr);
            $basedir = implode(DIRECTORY_SEPARATOR, $dirArr);

            if ($debugThis)
                $this->output(__FUNCTION__ . " Current dir=" . getcwd());
            if ($debugThis)
                $this->output(__FUNCTION__ . " going to chdir into $basedir");
            $success = chdir($basedir);

            $old_umask = umask(0);
            if ($debugThis)
                $this->output(__FUNCTION__ . " about to mkdir $dirname");
            $success = mkdir($dirname, 0777);
            umask($old_umask);
            clearstatcache();
//            $success = mkdir($localDir, 0777, true);
//            chmod($localDir, 0777);
//            $var = "chmod 777 $localDir -R";
//            shell_exec($var);
//            clearstatcache();
            if ($success) {
                $this->output("Made the local folder $localDir");
            } else {
                throw new Exception("ERROR! Failed to make the local folder $localDir");
            }
            return $success;
        } else {
            if ($debugThis)
                $this->output("The local subdir already exists=$localDir" . HR);
        }
        return true;
    }

    /**
     * 
     * @param type $path remote absolute path to the current pwd
     * @param type $localPath local absolute path to the target root dir
     * @return boolean always true
     * @throws Exception
     */
    function getAll($path, $localPath = NULL) {
        $debugThis = false;
        if (is_null($localPath) || $localPath == '') {
            $localPath = $this->localPath;
        }
        //$this->output('.');
        //$this->output(__FUNCTION__ . " started with remote path=$path");
        flush();

        $path = trim($path);
        if (empty($path))
            return true;
        $this->sftp->chdir($path);

        $path = trim($this->sftp->pwd());
        $this->output(__FUNCTION__ . "::Current remote path=" . $path);
        if (empty($path))
            throw new Exception('ERROR: I thought I was going into an actual folder but when I tried it came out empty');

        //$this->output('Folder contents=' . $this->pre($this->sftp->nlist()));

        $dirRawList = $this->sftp->rawlist();
        //$this->output('Current directory contents='.$this->pre($dirRawList));

        foreach ($dirRawList as $filename => &$remoteAttributes) {
            if ($debugThis)
                echo 'Looking at filename=' . print_r($filename, true) . BRNL;
            if (strcasecmp(trim($filename), '.') == 0) {
                if ($debugThis)
                    $this->output('Skipping dirItem=' . $filename);
                continue;
            }

            if ($debugThis)
                $this->output('Try getting dirItem=' . $path . "/$filename");

            if ($remoteAttributes['type'] == NET_SFTP_TYPE_REGULAR) {
                $remote_file = $filename;
                $local_file = rtrim($localPath, '/') . "/$remote_file";
                if ($debugThis)
                    $this->output("Remote attributes=" . print_r($remoteAttributes, true));
                if (file_exists($local_file)) {
                    $localAttr = array('mtime' => filemtime($local_file), 'size' => filesize($local_file));
                    if ($debugThis)
                        $this->output("Local attributes=" . print_r($localAttr, true));

                    if ($localAttr['size'] == $remoteAttributes['size']) {
                        if ($debugThis)
                            $this->output("Local and remote files are the same size ({$remoteAttributes['size']}) - skipping");
                        continue;
                    } else {
                        unlink($local_file);
                    }
                }

                $this->output("About to transfer remote=$path$remote_file to local=$local_file");
                $success = $this->sftp->get($remote_file, $local_file);
                if ($success) {
                    $this->output(__FUNCTION__ . "::Successfully transferred remote=$remote_file to local=$local_file");
                    flush();
                } else {
                    throw new Exception(__FUNCTION__ . "::ERROR! Failed to transfer file $filename");
                }
            } elseif ($remoteAttributes['type'] == NET_SFTP_TYPE_DIRECTORY) {
                $remoteDir = rtrim($path, '/') . "/$filename";
                
                //skip ones that have already been processed
                if(in_array($remoteDir, $this->db_processed)) {
                    $this->output(__FUNCTION__ . "::Skipping already-processed subdir=$remoteDir" . HR);
                    continue;
                }

                //skip empty subdirs
                $remoteDirList = $this->sftp->nlist($remoteDir);
                unset($remoteDirList['.']);
                if (empty($remoteDirList)) {
                    $this->output(__FUNCTION__ . "::Skipping empty subdir=$remoteDir" . HR);
                    continue;
                }
                
                $localDir = rtrim($localPath, '/') . "/$filename";
                if(file_exists($localDir) && is_dir($localDir)) {
                    $localAttr = array('mtime' => filemtime($localDir));
                    if ($localAttr['mtime'] == $remoteAttributes['mtime']) {
                        $this->output(__FUNCTION__ . "::Skipping same modified time subdir=$remoteDir" . HR);
                        continue;
                    }
                } else {
                    $this->ensure_localdir_exists($localDir);
                }
                
                //recurse into subdirectories
                $this->output(__FUNCTION__ . "::Going into subdir=$remoteDir" . HR);
                flush();

                $this->getAll($remoteDir, $localDir);
                $this->output(__FUNCTION__ . "::Done the folder $remoteDir" . HR);
                $this->dbLog($remoteDir);
                continue;
            } else {
                throw new Exception(__FUNCTION__ . '::ERROR! dirItem=' . $filename . ' is not a directory or a file:' . print_r($remoteAttributes, true));
            }
        }
        unset($remoteAttributes);

        return true;
    }

    function go($dir = '', $localDir = '') {
        try {
            echo 'in the go'.BRNL;
            $this->output('Switching to the local working directory');
            $absoluteLocalDir = $this->localPath . "/$localDir";
            $this->ensure_localdir_exists($absoluteLocalDir);

            $this->sftp = new Net_SFTP($this->host, $this->port, $this->timeout);
            $this->output('Net_SFTP set up');
            if (!$this->sftp->login($this->username, $this->password)) {
                throw new Exception("Login failed with username=$this->username");
            }
            $this->output('Login success');

            $this->showPath();

            $this->output('Switching to the remote working directory');
            $this->sftp->chdir($this->remoteRoot);
            $this->showPath();

            $path = $this->sftp->pwd();

            $this->output("Start the root getAll with path=$path");
            echo 'About to do the first getAll'.BRNL;
            $this->getAll($path . "$dir/", $absoluteLocalDir);

            //$this->output('Log:'.BRNL.'<PRE>'.print_r($this->sftp->getSFTPErrors(), true).'</PRE>'.HR);
            //output('Complete Log:'.BRNL.print_r($sftp->getSFTPLog(), true).HR);
        } catch (Exception $e) {
            echo 'ERROR! Exception caught=' . print_r($e, true);
            echo 'Complete Log:' . BRNL . $this->sftp->getSFTPLog() . HR;
            echo 'Log:' . BRNL . $this->sftp->getLastSFTPError() . HR;
            echo 'Backtrace:';
            debug_print_backtrace();
            die('died-o');
        }
    }

}


$config = new Config();
$markZilla = new MarkZilla($config);
$rootDirsToGet = array('filedir', 'lang', /* 'autobackups', */ 'mod_certificate', /* 'repository' */);
echo 'About to for loop'.BRNL;
foreach ($rootDirsToGet as $dir) {
    $markZilla->go($dir, $dir);
    $markZilla->output('Done all the folders');
}

