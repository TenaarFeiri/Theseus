<?php
    declare(strict_types=1); // Force strict types.

    use function Safe\sleep;

    /**
     * Master controller for Theseus system
     * Handles module routing and request processing
     * 
     * @package Theseus
     */
    define("access", TRUE); // Define the access.
    $init = $_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR . "starfall/theseus/init/init.php";
    require_once $init; // Require the initial file.
    if(!defined("INIT_INCLUDED")) {
        http_response_code(400);
        echo "Initialization failed.";
        exit();
    }
    class TheseusMasterController {
        /**
         * Module path configurations
         * @var array<string,string> Map of module names to file paths
         */
        private const MODULES = [ // Modules we can use, and their directory paths.
            "user" => DIRECTORY_SEPARATOR . "source/classes/users/UserController",
            "character" => DIRECTORY_SEPARATOR . "source/classes/characters/CharacterController",
            "rptool" => DIRECTORY_SEPARATOR . "source/classes/system/RptoolController",
            "menu" => DIRECTORY_SEPARATOR . "source/classes/system/MenuController",
        ];
        /**
         * Execute requested module function
         * 
         * @return ?array Response from module process() or null if invalid
         * @throws RuntimeException If module returns empty response
         */

        public function __construct() {
            require_once THESEUS_PATH . "source/classes/parsers/XMLHandler.php";
            Data::setXml(new XMLHandler(THESEUS_XML_PATH)); // Set the XMLHandler.
            if(!Data::getXml()) {
                throw new RuntimeException("XMLHandler not provided.");
            }
        }

        /**
         * Verify parameters are set; controller will not run without them
         * 
         * @return bool True if parameters are set
         * @throws RuntimeException If no parameters set
         */
        private function verifyParams() : void { // Bespoke function to verify parameters.
            if(empty(Data::getParams())) {
                throw new RuntimeException("No parameters set.");
            }
            if(!isset(Data::getParams()["module"])) {
                throw new RuntimeException("No module set.");
            }
            if(!isset(Data::getParams()["function"])) {
                throw new RuntimeException("No function set.");
            }
        }

        /**
         * Runs the controller.
         *
         * 
         */
        public function run() : ?Array {
            $this->verifyParams();
            // Execute the controller.
            $module = $this->loadModule();
            if(!$module) {
                return null;
            }
            $responseArray = $module->process();
            if(empty($responseArray)) {
                throw new RuntimeException("No response array returned from module.");
            }
            return $responseArray;
        }
        /**
         * Load and instantiate a module
         * 
         * @return ?object Instantiated module or null if invalid
         * @throws RuntimeException If module path invalid or class not found
         */
        private function loadModule() : ?Object {
            if(!isset(Data::getParams()["module"])) {
                return null;
            }
            $module = Data::getParams()["module"];
            // Whitelist check
            if(!array_key_exists($module, self::MODULES)) {
                return null;
            }
            // Construct and validate path
            $modulePath = theseusPath() . self::MODULES[$module] . ".php";
            $realPath = realpath($modulePath);
            // Ensure path exists and is within allowed directory
            if($realPath === false || 
               !str_starts_with($realPath, realpath(theseusPath()))) {
                throw new RuntimeException("Invalid module path");
            }
            require_once $realPath;
            $moduleClass = basename(self::MODULES[$module]);
            if(!class_exists($moduleClass)) {
                throw new RuntimeException("Module class does not exist");
            }
            return new $moduleClass();
        }
    }

    // Executive code below.
    $controller = null;
    try {
        $controller = new TheseusMasterController();
        $result = $controller->run();
        if(!$result) {
            http_response_code(400);
            echo "No result returned from controller.";
            exit();
        }
    } catch (Exception $e) {
        http_response_code(400); // Internal Server Error
        if ($controller === null) {
            echo "Controller initialization failed: " . $e->getMessage();
        } else if (Data::getXml() === null) {
            echo "XML Handler failed to load. Please contact administrator.";
        } else {
            echo Data::getXml()->getString("//errors/errorOccurred") . $e->getMessage();
        }
        // Add log feature later.
        exit();
    }
    if(THESEUS_DEBUG) {
        echo "Result: ", PHP_EOL;
        var_dump($result);
    }
    $isStatusNotSet = !isset($result["status"]);
    $isStatusNotRunNewCommand = !$isStatusNotSet && $result["status"] != "runNewCommand";
    if (THESEUS_DEBUG && ($isStatusNotSet || $isStatusNotRunNewCommand)) {
        echo PHP_EOL, "No new command to run.", PHP_EOL;
        exit();
    }
    $loopCount = 0;
    $maxLoops = 6;
    $maxRetries = 3;
    $retryCount = 0;
    try {
        $response = null;
        while($loopCount < $maxLoops) {
            $loopCount++;
            $retryCount = 0;
            while($retryCount < $maxRetries) {
                $retryCount++;
                $response = $controller->run();
                if($response) {
                    break;
                }
                sleep(1);
            }
            if($response) {
                break;
            }
        }
    } catch (Exception $e) {
        http_response_code(400); 
        echo Data::getXml()->getString("//errors/errorOccurred") . $e->getMessage();
        // Add log feature later.
        exit();
    }
    