<?php
    declare(strict_types=1);
    if(!defined("access"))
    {
        // If it's not required by our scripts, just exit.
        exit("Access denied.");
    }
    header('Content-Type: text/plain');
    define('INIT_INCLUDED', true);
    define('THESEUS_SYSTEM_NAME', 'Theseus RP Tool');
    define('THESEUS_VERSION', '1.0.0');

	// Error reporting.
	ini_set('display_errors', '1');
	ini_set('display_startup_errors', '1');

    // Superfunctions \\

    /**
     * Returns the root path of the server.
     * 
     * @return string
     */
    function rootPath() : String {
        static $path = null;
        if ($path === null) {
            $path = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);
        }
        return $path;
    }

    /**
     * Returns the path to the Theseus system.
     * 
     * @return string
     */
    function theseusPath() : String {
        static $path = null;
        if ($path === null) {
            $path = rootPath() . DIRECTORY_SEPARATOR . 
                    'starfall' . DIRECTORY_SEPARATOR . 
                    'theseus' . DIRECTORY_SEPARATOR;
        }
        return $path;
    }

    /**
     * Returns the path to the XML strings file.
     * 
     * @return string
     */
    function xmlStringsPath() : String {
        return 'source/data/xml/StandardStrings.xml';
    }

    define('THESEUS_PATH', theseusPath());
    define('THESEUS_CLASS_PATH', THESEUS_PATH . 'source/classes/');
    define('THESEUS_XML_PATH', THESEUS_PATH . xmlStringsPath());

    require_once theseusPath() . "source/classes/Data.php";
    Data::setParams(!empty($_POST) ? $_POST : $_GET);
    // Get parent directory path safely
    $databasePath = dirname(rootPath()) 
        . DIRECTORY_SEPARATOR . 'database.php';

    // Validate path exists
    if (!file_exists($databasePath)) {
        exit('Database configuration file not found');
    }
    require_once $databasePath;
    $initDb = new Database();
    $initDbConn = $initDb->connect();
    $stmt = "SELECT maintenance,debug FROM settings";
    $result = $initDbConn->query($stmt);
    $result = $result->fetch(PDO::FETCH_ASSOC); // Fetch the status of the system as an int.
    $systemStatus = [
        "maintenance" => $result['maintenance'] == 1 ? true : false,
        "debug" => $result['debug'] == 1 ? true : false
    ];
    define("THESEUS_MAINTENANCE", $systemStatus["maintenance"]);
    define("THESEUS_DEBUG", $systemStatus["debug"]);
    $initDb = null; // Kill the connection.
    $initDbConn = null; // And wipe the connection object as well.
    if(THESEUS_MAINTENANCE) {
        // If we are in maintenance mode, we'll just exit.
        http_response_code(503);
        exit("The system is currently in maintenance mode.");
    }
    if(THESEUS_DEBUG) {
        // If we are in debug mode, we'll enable error reporting.
        error_reporting(E_ALL);
        // Then check $_SERVER for any headers containing: HTTP_X_SECONDLIFE_
        $headers = array_filter($_SERVER, function($key) {
            return strpos($key, 'HTTP_X_SECONDLIFE_') === 0;
        }, ARRAY_FILTER_USE_KEY);
        // If there are none, we're probably developing so we'll add some.
        if(count($headers) == 0) {
            $_SERVER["HTTP_X_SECONDLIFE_SHARD"] = "Production";
            $_SERVER["HTTP_X_SECONDLIFE_REGION"] = "Starfall Roleplay";
            $_SERVER["HTTP_USER_AGENT"] = "Second Life LSL/srv.version (http://secondlife.com)";
            $_SERVER["HTTP_X_SECONDLIFE_OWNER_KEY"] = "5675c8a0-430b-4281-af36-60734935fad3";
            $_SERVER["HTTP_X_SECONDLIFE_OWNER_NAME"] = "Tenaar Feiri";
        } else {
            // Check if HTTP_USER_AGENT contains "Second Life LSL/" and (http://secondlife.com) or the https version.
            $userAgent = $_SERVER["HTTP_USER_AGENT"];
            $isValidSL = (
                str_starts_with($userAgent, "Second Life LSL/") && 
                (strpos($userAgent, "http://secondlife.com") !== false || 
                strpos($userAgent, "https://secondlife.com") !== false)
            );
        }
        // Now check for X-System-name header and add it if it doesn't exist.
        if(!isset($_SERVER["HTTP_X_SYSTEM_NAME"])) {
            $_SERVER["HTTP_X_SYSTEM_NAME"] = THESEUS_SYSTEM_NAME;
        }
        var_dump($_SERVER);
        echo PHP_EOL, "------------------", PHP_EOL, PHP_EOL;
    }
    else {
        // Otherwise, we'll disable error reporting.
        error_reporting(0);
    }
    // Verify that the system is running in a Second Life environment.
    if(!isset($_SERVER["HTTP_X_SECONDLIFE_SHARD"]) || 
       !isset($_SERVER["HTTP_X_SECONDLIFE_REGION"]) || 
       !isset($_SERVER["HTTP_X_SECONDLIFE_OWNER_KEY"]) || 
       !isset($_SERVER["HTTP_X_SECONDLIFE_OWNER_NAME"])) {
        http_response_code(400);
        exit("Invalid environment. Please run this script in a Second Life environment.");
    }
    // If system name header doesn't match, we'll exit.
    if($_SERVER["HTTP_X_SYSTEM_NAME"] !== THESEUS_SYSTEM_NAME) {
        http_response_code(400);
        exit("Invalid system name. Please run this script in a Second Life environment.");
    }
    // Global requires & vars
    require_once theseusPath() . "source/classes/db/DatabaseHandler.php";


