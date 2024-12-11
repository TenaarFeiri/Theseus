<?php
    declare(strict_types=1);
    require_once theseusPath() . "source/classes/interfaces/UserControllerInterface.php";
    /**
     * Handles all functions related to user management.
     * 
     * @implements UserControllerInterface
     * @package Theseus
     * @see UserControllerInterface
     * @see XMLHandler
     * @see DatabaseHandler
     * @see LegacyCharacterImporter
     * 
     */
    class UserController implements UserControllerInterface {
        private string $uuid;
        private string $username;
        private const VALID_FUNCTIONS = [
            "user" => [
                "checkUser",
                "getUser"
            ]
        ];
        /*public function __construct() {
            $params = Data::getParams();
            if(isset($params['uuid']) && isset($params['username']) 
                && !empty($params['uuid']) && !empty($params['username'])
                && is_string($params['uuid']) && is_string($params['username'])) {
                if($params['uuid'] == "" || $params['username'] == "") {
                    throw new RuntimeException(__CLASS__ . ": " . Data::getXml()->getString("//errors/emptyString") . " (uuid or username)");
                }
                $this->uuid = $params['uuid'];
                $this->username = $params['username'];
            }
            else {
                if(!isset($params['uuid']) || empty($params['uuid'])) {
                    if(!isset($_SERVER['HTTP_X_SECONDLIFE_OWNER_KEY'])) {
                        throw new RuntimeException("Missing SL owner key");
                    }
                    $this->uuid = $_SERVER['HTTP_X_SECONDLIFE_OWNER_KEY'];
                }
                if(!isset($params['username']) || empty($params['username'])) {
                    if(!isset($_SERVER['HTTP_X_SECONDLIFE_OWNER_NAME'])) {
                        throw new RuntimeException("Missing SL owner name");
                    }
                    $this->username = $_SERVER['HTTP_X_SECONDLIFE_OWNER_NAME'];
                }
            }  
            if(!$this->uuid || !$this->username) { // Verify that both vars are not null, or empty.
                throw new RuntimeException(__CLASS__ . ": " . Data::getXml()->getString("//errors/userControlNoParamsProvidedToClass"));
            }
        }*/

        public function __construct() {
            $params = Data::getParams();
            
            // Validate and assign uuid
            $this->uuid = $params['uuid'] ?? $_SERVER['HTTP_X_SECONDLIFE_OWNER_KEY'] ?? null;
            if (empty($this->uuid) || !is_string($this->uuid)) {
                throw new RuntimeException("Missing or invalid UUID");
            }
            
            // Validate and assign username
            $this->username = $params['username'] ?? $_SERVER['HTTP_X_SECONDLIFE_OWNER_NAME'] ?? null;
            if (empty($this->username) || !is_string($this->username)) {
                throw new RuntimeException("Missing or invalid username");
            }
            
            // Final validation
            if (!$this->uuid || !$this->username) {
                throw new RuntimeException(__CLASS__ . ": " . Data::getXml()->getString("//errors/userControlNoParamsProvidedToClass"));
            }
        }

        /**
         * Processes the request and calls the appropriate function.
         * 
         * @return ?array Returns array or null.
         * @throws RuntimeException If no function is set, or if the function is invalid
         * 
         */
        public function process() : ?Array {
            // Process the parameters.
            $parameters = Data::getParams();
            if(!isset($parameters['function']) || empty($parameters['function'])) {
                return null;
            }
            if(!in_array($parameters['function'], self::VALID_FUNCTIONS['user'])) {
                return null;
            }
            $method = $parameters['function'];
            (array)$runMethod = [];
            if(!method_exists($this, $method)) {
                throw new RuntimeException(__CLASS__ . ": " . Data::getXml()->getString("//errors/noMethod"));
            }
            try {
                $runMethod = $this->$method();
            } catch (Exception $e) {
                throw new RuntimeException(__CLASS__ . ": " . $e->getMessage());
            }
            return $runMethod;
        }

        private function checkUser() : Array {
            if(!isset(Data::getParams()["url"]) && 
                (!isset(Data::getParams()["runtimeScript"]) || 
                empty(Data::getParams()["runtimeScript"]) || 
                Data::getParams()["runtimeScript"] !== "1")) {
                throw new RuntimeException(
                    __CLASS__ . ": " . 
                    Data::getXml()->getString("//errors/userControlNoParamsProvided") . 
                    " No URL provided, not called by non-agent script."
                );
            }
            // Validate URL.
            $url = Data::getParams()["url"];
            if(!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException(__CLASS__ . ": " . Data::getXml()->getString("//errors/userControlInvalidUrl"));
            }
            $updateUrl = true;
            if(isset(Data::getParams()["runtimeScript"]) && Data::getParams()["runtimeScript"] === "1") {
                $updateUrl = false;                
            }
            $database = new DatabaseHandler(); // Included in init.php, without which this will fail.
            // Build the query arrays.
            $select = ["*"];
            $where = ["player_uuid" => $this->uuid];
            $database->connect();
            
            (array)$result = $database->select("players", $select, $where, ["limit" => 1]);
            
            if(THESEUS_DEBUG) {
                echo PHP_EOL, "checkUser: ", PHP_EOL;
                var_dump($result);
            }

            // Now we'll check if the user exists.
            if(!empty($result)) {
                $updatePlayer = [
                    "player_last_online" => date("m-d-Y")
                ];
                if($updateUrl) {
                    $updatePlayer["player_titler_url"] = $url;
                }
                $where = ["player_uuid" => $this->uuid];
                $database->beginTransaction();
                $update = $database->update("players", $updatePlayer, $where);
                if(!$update) {
                    $database->rollBack();
                    throw new RuntimeException(__CLASS__ . ": " . Data::getXml()->getString("//errors/userControlUpdateFailed"));
                }
                $database->commit();
                $database->disconnect();
                $result[0]["player_titler_url"] = $url;
                $params = Data::getParams();
                $params["function"] = "load";
                $params["module"] = "character";
                $params["characterId"] = $result[0]["player_current_character"];
                $params["usrDetails"] = $result[0];
                Data::setParams($params);
                $result = [
                    "status" => "runNewCommand"
                ];
                return $result; // If the user exists, we'll return the result.
            }
            $database->disconnect();
            $database->connect("rp_tool");
            $where = ["uuid" => $this->uuid];
            // Slightly different data structure here.
            (array)$importResult = $database->select("users", $select, $where, ["limit" => 1]);
            if(THESEUS_DEBUG) {
                echo PHP_EOL, "checkUser (import): ", PHP_EOL;
                var_dump($importResult);
            }
            $date = new DateTime("now", new DateTimeZone("America/Los_Angeles"));
            $date = $date->format("m-d-Y");
            $newUserArray = [ 
                "player_name" => $_SERVER['HTTP_X_SECONDLIFE_OWNER_NAME'],
                "player_uuid" => $this->uuid,
                "player_created" => $date,
                "player_last_online" => $date,
                "player_current_character" => 0
            ];
            $database->disconnect(); // Disconnect from the old database.
            if(!empty($importResult)) {
                // If not empty, edit the newUserArray some.
                $newUserArray["player_created"] = $importResult[0]["registered"];
                $newUserArray["player_last_online"] = $importResult[0]["lastactive"];
                $newUserArray["player_legacy_id"] = $importResult[0]["id"];
                // And finally, we will put the lastchar as a negative number, so we know it's a legacy character.
                $newUserArray["player_current_character"] = $importResult[0]["lastchar"] * -1;
            }
            if(THESEUS_DEBUG) {
                echo PHP_EOL, "checkUser (newUserArray): ", PHP_EOL;
                var_dump($newUserArray);
            }
            // Make the insert.
            $database->connect();
            $database->beginTransaction();
            $insert = $database->insert("players", $newUserArray);
            if(!$insert) {
                $database->rollBack();
                throw new RuntimeException(__CLASS__ . ": " . Data::getXml()->getString("//errors/userControlInsertFailed"));
            }
            $database->commit();
            $database->disconnect();
            $importer = null;
            require_once theseusPath() . "/source/classes/users/LegacyCharacterImporter.php";
            try {
                $importer = new LegacyCharacterImporter($this->uuid);
                $importer->importCharacters();
            } catch (Exception $e) {
                throw new RuntimeException($e->getMessage());
            }
            if(!$importer) {
                throw new RuntimeException(Data::getXml()->getString("//errors/userControlImportFailed"));
            }
            $database->connect();
            $where = ["player_uuid" => $this->uuid];
            $result = $database->select("players", $select, $where, ["limit" => 1]); // Now we'll reselect the user data.
            $updatePlayer = [
                "player_last_online" => date("m-d-Y")
            ];
            echo PHP_EOL, "checkUser (result): ", PHP_EOL;
            print_r($result);
            echo PHP_EOL, "------------", PHP_EOL;
            if($updateUrl) {
                $updatePlayer["player_titler_url"] = $url;
            }
            $where = ["player_uuid" => $this->uuid];
            $database->beginTransaction();
            $update = $database->update("players", $updatePlayer, $where);
            if(!$update) {
                $database->rollBack();
                throw new RuntimeException(__CLASS__ . ": " . Data::getXml()->getString("//errors/userControlUpdateFailed"));
            }
            $database->commit();
            $database->disconnect();
            // Update parameters.
            // After doing a checkuser, we will auto-load.
            $result[0]["player_titler_url"] = $url;
            $params = Data::getParams();
            $params["function"] = "load";
            $params["module"] = "character";
            $params["characterId"] = $result[0]["player_current_character"];
            $params["usrDetails"] = $result[0];
            Data::setParams($params);
            $result = [
                "status" => "runNewCommand"
            ];
            return $result; // And then return it.
        }
    }