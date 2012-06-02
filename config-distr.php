<?php
/**
 * Config
 *
 * @author Mark van Hoek
 */
class Config {
    public $host = 'host.whereami.com';
    public $port = 22;
    public $username = 'sftp_username';
    public $password = 'sftp_password';
    public $timeout = PHP_INT_MAX;
    public $remoteRoot = '/absolute/remoteroot/folder'; //no trailing slash
    public $rootDirsToGet = NULL; //array of strings
    public $localPath = '/absolute/localroot/path'; //no trailing slash
    public $log_path = 'syncLog.txt'; //no trailing slash
    public $db_host = 'e.g.127.0.0.1';
    public $db_databaseName = 'db_name';
    public $db_user = 'db_username';
    public $db_pw = 'db_password';
}
