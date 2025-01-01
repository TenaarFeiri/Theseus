<?php
    require_once THESEUS_PATH . "source/classes/interfaces/CharacterControllerInterface.php";
    /**
     * The Character Controller class for all character operations.
     * It handles features such as saving,
     * loading, deleting and updating characters
     * It also deals with the creation of new characters, updating and reading stats,
     * engaging dice rolls, etc.
     * It will default to the active session user if no other UUID parameters are supplied.
     * 
     * @implements CharacterControllerInterface
     */
    class CharacterController implements CharacterControllerInterface {
        private bool $error = false;
        private const VALID_FUNCTIONS = [
            "save", "load", "delete", "update", "showCharList", "create"
        ];

        private const PAGE_LIMIT = 9;

        public function __construct() {
            // Set an autoloader for system classes.
            spl_autoload_register(function($class) {
                if(file_exists(THESEUS_PATH . "source/classes/system/{$class}.php")) {
                    require_once THESEUS_PATH . "source/classes/system/{$class}.php";
                }
            });
        }

        /**
         * Processes the request and calls the appropriate function.
         * 
         * @param array $parameters The parameters to process
         * @return ?array The response from the function or null if invalid
         * @throws RuntimeException If no function is set, or if the function is invalid
         */
        public function process() : ?Array {
            // First of all, if module is not set to "character", how did we get here?
            $parameters = Data::getParams();
            if($parameters["module"] != "character") {
                return null;
            }
            // Check if the function is set.
            if(!isset($parameters["function"]) || empty($parameters["function"])) {
                throw new RuntimeException("No function set.");
            } else {
                // Check if the function exists in this class as a method.
                if(!method_exists($this, $parameters["function"])) {
                    throw new RuntimeException("Function {$parameters['function']} does not exist in this class: " . __CLASS__);
                } else if(!in_array($parameters["function"], self::VALID_FUNCTIONS)) {
                    throw new RuntimeException("Function {$parameters['function']} is not a valid function in this class: " . __CLASS__);
                }
            }
            // If we're still here, we can call the function.
            $result = $this->{$parameters["function"]}();
            return $result;
        }
        /**
         * Shows a list of characters for the user.
         * 
         * @param array $parameters An array of parameters expecting "uuid" and "page", both optional.
         * @return ?array The response from the function or null if invalid. Returns 1 on success.
         * @throws RuntimeException If the user is not found, or if the database query fails.
         */
        private function showCharList() : ?Array {
            $parameters = Data::getParams();
            // Show a list of characters for the user.
            if(!isset($parameters["uuid"]) || empty($parameters["uuid"])) {
                // If no UUID is set, we'll default to the active session user.
                $parameters["uuid"] = $_SERVER["HTTP_X_SECONDLIFE_OWNER_KEY"];
            }
            if(!isset($parameters["page"]) || empty($parameters["page"])) {
                $parameters["page"] = 1;
            }
            $parameters["page"] = filter_var($parameters["page"], FILTER_VALIDATE_INT, [
                'options' => [
                    'default' => 1, // Default value if validation fails
                    'min_range' => 1, // Minimum value
                    'max_range' => PHP_INT_MAX // Maximum value
                ]
            ]);
            
            if ($parameters["page"] === false) {
                $parameters["page"] = 1; // Fallback to 1 if validation fails
            }
            $result = [];
            $parameters["usrDetails"] = [];
            try {
                $database = new DatabaseHandler();
                $database->connect();
                $select = ["player_id", "player_uuid", "player_titler_url"];
                $where = ["player_uuid" => $parameters["uuid"]];
                $result = $database->select("players", $select, $where);
                if(empty($result)) {
                    throw new RuntimeException($GLOBALS['strings']->errors->userNotFound . " UUID: {$parameters['uuid']}.");
                }
                if(THESEUS_DEBUG) {
                    var_dump($result);
                }
                if(!isset($result[0]["player_id"]) || empty($result[0]["player_id"])) {
                    throw new RuntimeException($GLOBALS['strings']->errors->userNotFound . " UUID: {$parameters['uuid']}.");
                }
                $parameters["usrDetails"] = $result[0];
                $id = $result[0]["player_id"];
                // Limit to 9 entries per page.
                $select = ["character_id", "character_name"];
                $where = ["player_id" => $id, "deleted" => 0];
                $options = [
                    "limit" => self::PAGE_LIMIT, 
                    "offset" => ($parameters["page"] - 1) * self::PAGE_LIMIT, 
                    "order" => "character_id ASC"
                ];
                $result = $database->select("player_characters", $select, $where, $options);
                if(empty($result) && $parameters["page"] > 1) {
                    $parameters["page"] = 1;
                    $options["offset"] = ($parameters["page"] - 1) * self::PAGE_LIMIT;
                    $result = $database->select("player_characters", $select, $where, $options); // Try again if page is empty.
                    if(empty($result)) { // If still empty, throw an error.
                        throw new RuntimeException("No characters found for user.");
                    }
                }
            } catch (Exception $e) {
                throw new RuntimeException($e->getMessage());
            } finally {
                $database->disconnect();
            }
            // Now we'll build a menu.
            if(THESEUS_DEBUG) {
                var_dump($result);
            }
            $menu = new MenuController();
            $menu->setModule("CharacterController"); // Tells the LSL script which module to send its response to.
            $text = "CHARACTERS (page {$parameters["page"]})" . PHP_EOL . PHP_EOL;
            $count = 0;
            $numericArray = [];
            $characterIds = [];
            foreach($result as $character) {
                $count++;
                $numericArray[] = $count;
                $text .= "{$count}. {$character['character_name']}" . PHP_EOL;
                $characterIds[] = $character['character_id'];
            }
            $numericArray[] = $parameters["page"] > 1 ? "<<" : "--";
            $numericArray[] = "Cancel";
            $numericArray[] = $parameters["page"] < PHP_INT_MAX ? ">>" : " ";
            // Add two entries value 0 to characterIds to handle page navigation.
            $characterIds[] = 0;
            $characterIds[] = "cancel";
            $characterIds[] = 0;
            //$numericArray = array_reverse($numericArray);
            //$characterIds = array_reverse($characterIds);
            if(THESEUS_DEBUG) {
                var_dump($numericArray);
                var_dump($characterIds);
                echo PHP_EOL, $text, PHP_EOL;
            }
            $menu->setDialogText(rawurlencode($text));
            $menu->setDialog($numericArray, $characterIds);
            $menu->isTextBox(false);
            if($menu->confirmDialog()) {
                $dialog = $menu->getFinishedDialog();
                $dialog["page"] = $parameters["page"];
                if(THESEUS_DEBUG) {
                    echo "Confirmed.", PHP_EOL;
                    print_r($dialog);
                }
                if(THESEUS_DEBUG) {
                    echo "JSON: ", json_encode($dialog), PHP_EOL;
                }
                try {
                    $sysComms = new SysComms([
                    "uuid" => $parameters["usrDetails"]["player_uuid"],
                    "url" => $parameters["usrDetails"]["player_titler_url"]
                    ]);
                    $sysComms->addToCurlDataArray("cmd", "showCharList");
                    $sysComms->addToCurlDataArray("scriptFunc", "dialogs");
                    $sysComms->addToCurlDataArray("data", $dialog);
                    if(!$sysComms->sendMessage()) {
                        throw new RuntimeException("Failed to send message to client system.");
                    }
                } catch (Exception $e) {
                    throw new RuntimeException("Error sending message to client system: " . $e->getMessage());
                }
            }
            return [1]; // Return 1 to indicate success.
        }
        
        private function normalizeRGBColor(string $color): string {
            if(!$this->validVector($color)) {
                return "<1.0,1.0,1.0>";
            }
            // Strip brackets and split
            $color = trim($color, '<>');
            $values = explode(',', $color);
            // Check if all values are already normalized
            $allNormalized = array_reduce($values, function($carry, $value) {
                $value = (float)$value;
                return $carry && ($value >= 0.0 && $value <= 1.0);
            }, true);
            if ($allNormalized) {
                return '<' . implode(',', $values) . '>';
            }
            // Validate and normalize each component
            $normalized = array_map(function($value) {
                $value = (float)$value;
                return min(1.0, max(0.0, $value));
            }, $values);
            
            return '<' . implode(',', $normalized) . '>';
        }

        private function validVector(string $vector) : int | false {
            return preg_match("/^<([0-9]*\.?[0-9]+),([0-9]*\.?[0-9]+),([0-9]*\.?[0-9]+)>$/", $vector);
        }

        private function update() : ?Array {
            $parameters = Data::getParams();
            if(!isset($parameters["uuid"]) || empty($parameters["uuid"])) {
                $parameters["uuid"] = $_SERVER["HTTP_X_SECONDLIFE_OWNER_KEY"];
            }
            if(!isset($parameters["characterId"]) || empty($parameters["characterId"])) {
                throw new RuntimeException("Character ID not set.");
            }
            $user = [];
            $database = new DatabaseHandler();
            try {
                $database->connect();
                $select = ["*"];
                $where = ["player_uuid" => $parameters["uuid"]];
                $user = $database->select("players", $select, $where);
                if(empty($user)) {
                    throw new RuntimeException("User not found.");
                }
                if($parameters["characterId"] != $user[0]["player_current_character"]) {
                    throw new RuntimeException("Invalid character or character not found.");
                }
                $characterJsonArray = json_decode($parameters["data"], true);
                if(THESEUS_DEBUG) {
                    var_dump($characterJsonArray);
                }
                if(json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException("Invalid JSON data.");
                }
                if(!isset($characterJsonArray["name"]) || empty($characterJsonArray["name"])) {
                    throw new RuntimeException("Character name is missing; name cannot be empty.");
                }
                if(!isset($characterJsonArray["desc"])) {
                    throw new RuntimeException("Character description field is missing; this character needs manual repair.");
                }
                $characterJsonArray["color"] = $this->normalizeRGBColor($characterJsonArray["color"]); // Validate & normalise color.
                // All user input is now validated.
                $update = [
                    "character_name" => urldecode($characterJsonArray["name"]),
                    "character_description" => urldecode($characterJsonArray["desc"]),
                    "afk_ooc" => $characterJsonArray["afk_ooc"],
                    "color" => $characterJsonArray["color"],
                    "titler_offset" => $characterJsonArray["offset"],
                    "tags" => $characterJsonArray["tags"]
                ];
                $where = ["character_id" => $user[0]["player_current_character"], "player_id" => $user[0]["player_id"]];
                $database->update("player_characters", $update, $where);
            } catch (Exception $e) {
                throw new RuntimeException("Error updating character: " . $e->getMessage());
            } finally {
                $database->disconnect();
            }
            // No errors? Good. Kick back to the master controller & reload the character.
            Data::updateParam("updating", "1");
            Data::updateParam("function", "load");
            Data::deleteParam("characterId"); // Force reload of active character by deleting characterId.
            return [1, "status" => "runNewCommand"];
        }

        /**
         * Loads a character belonging to the current user.
         * 
         * @return ?array The response from the function or null if invalid. Returns 1 on success.
         * @throws RuntimeException If the character ID is invalid, or if the database query fails.
         * 
         * Receives character id from "characterId" or extracts it from the player_characters table if negative, zero or missing.
         */
        private function load() : ?Array {
            $output = [];
            $parameters = Data::getParams();
            // If the character ID is negative, identify the character_id from the player_characters table which
            // corresponds to the legacy character ID, simply called "legacy" (0 if not a legacy character).
            try {
                $database = new DatabaseHandler();
                $database->connect();
                $result = [];
                if(!isset($parameters["usrDetails"]) || empty($parameters["usrDetails"])) {
                    // If not provided, load it up!
                    $select = ["*"];
                    $where = ["player_uuid" => isset($parameters["uuid"]) && !empty($parameters["uuid"]) ? 
                               $parameters["uuid"] : $_SERVER["HTTP_X_SECONDLIFE_OWNER_KEY"]
                            ];
                    $result = $database->select("players", $select, $where);
                    if(empty($result)) {
                        throw new RuntimeException("User not found.");
                    }
                    $parameters["usrDetails"] = $result[0];
                }
                if(!isset($parameters["characterId"]) || empty($parameters["characterId"])) {
                    $parameters["characterId"] = $parameters["usrDetails"]["player_current_character"];
                }
                $select = ["*"];
                if($parameters["characterId"] < 0) {
                    $where = ["player_id" => $parameters["usrDetails"]["player_id"], "legacy" => abs($parameters["characterId"]), "deleted" => 0];
                }
                else {
                    $where = ["character_id" => $parameters["characterId"]];
                }
                $result = $database->select("player_characters", $select, $where);
                if(empty($result)) {
                    $where = ["player_id" => $parameters["usrDetails"]["player_id"], "deleted" => 0];
                    $options = ["limit" => 1, "order" => "character_id DESC"];
                    $result = $database->select("player_characters", $select, $where, $options);
                    if(empty($result)) {
                        throw new RuntimeException("Character not found.");
                    }
                }
                $parameters["characterId"] = $result[0]["character_id"];
                $database->beginTransaction();
                $update = ["player_current_character" => $parameters["characterId"]];
                $where = ["player_id" => $parameters["usrDetails"]["player_id"], "player_uuid" => $parameters["usrDetails"]["player_uuid"]];
                $database->update("players", $update, $where);
                $database->commit();
            } catch (Exception $e) {
                $database->rollBack();
                throw new RuntimeException("Error loading character: " . $e->getMessage());
            } finally {
                if($this->error) {
                    $database->disconnect();
                }
            }
            // Build output array.
            if(!isset($parameters["usrDetails"]["color"]) || 
                empty($parameters["usrDetails"]["color"]) || 
                !preg_match("/^<([0-9]*\.?[0-9]+),([0-9]*\.?[0-9]+),([0-9]*\.?[0-9]+)>$/", 
                    $parameters["usrDetails"]["color"]) ||
                max(array_map('floatval', explode(',', trim($parameters["usrDetails"]["color"], '<>')))) > 1.0 ||
                min(array_map('floatval', explode(',', trim($parameters["usrDetails"]["color"], '<>')))) < 0.0
            ) {
                $parameters["usrDetails"]["color"] = "<1.0,1.0,1.0>";
            }
            $output = [
                "char_id" => $result[0]["character_id"],
                "name" => rawurlencode($result[0]["character_name"]),
                "afk_ooc" => $result[0]["afk_ooc"],
                "desc" => rawurlencode($result[0]["character_description"]),
                "color" => $result[0]["color"],
                "offset" => $result[0]["titler_offset"],
                "tags" => $result[0]["tags"]
            ];
            unset($result); // No longer needed.
            if(THESEUS_DEBUG) {
                var_dump($output);
            }
            try {
                $sysComms = new SysComms([
                    "uuid" => $parameters["usrDetails"]["player_uuid"],
                    "url" => $parameters["usrDetails"]["player_titler_url"]
                ]); // Initialize the system communications class.
                $updating = isset($parameters["updating"]) && $parameters["updating"] == 1;
                $sysComms->addToCurlDataArray("cmd", $updating ? "updateCharacter" : "loadCharacter");
                $sysComms->addToCurlDataArray("scriptFunc", "characters");
                $sysComms->addToCurlDataArray("data", $output);
                if(!$sysComms->sendMessage()) {
                    throw new RuntimeException("Failed to send message to client system.");
                }
            } catch (Exception $e) {
                throw new RuntimeException("Error sending message to client system: " . $e->getMessage());
            }
            try {
                $database->update("players", ["player_current_character" => $parameters["characterId"]], ["player_id" => $parameters["usrDetails"]["player_id"]]);
            } catch (Exception $e) {
                throw new RuntimeException("Error updating current character: " . $e->getMessage());
            } finally {
                $database->disconnect();
            }
            return [1];
        }

        /**
         * Creates a new character.
         */
        private function create() : ?array {
            $parameters = Data::getParams();
            // If no uuid is provided, use the one in headers.
            if(!isset($parameters["uuid"]) || empty($parameters["uuid"])) {
                $parameters["uuid"] = $_SERVER["HTTP_X_SECONDLIFE_OWNER_KEY"];
            }
            // If name isn't set or defined, just make it "My name".
            if(!isset($parameters["name"]) || empty($parameters["name"])) {
                $parameters["name"] = "My name";
            }
            $database = new DatabaseHandler();
            try {
                $database->connect();
                $select = ["player_id"];
                $where = ["player_uuid" => $parameters["uuid"]];
                $result = $database->select("players", $select, $where);
                if(empty($result)) {
                    throw new RuntimeException("User not found.");
                }
                $id = $result[0]["player_id"];
                $database->beginTransaction();
                $insert = [
                    "player_id" => $id,
                    "character_name" => $parameters["name"]
                ];
                $database->insert("player_characters", $insert);
                $lastInsert = $database->lastInsertId();
                if($lastInsert == 0) {
                    $database->rollBack();
                    throw new RuntimeException("Error creating character.");
                }
                $database->commit();
                Data::updateParam("characterId", $lastInsert);
                Data::updateParam("module", "character");
                Data::updateParam("updating", "0");
                Data::updateParam("function", "load");
                return [1, "status" => "runNewCommand"];
            } catch (Exception $e) {
                $database->rollBack();
                throw new RuntimeException("Error creating character: " . $e->getMessage());
            } finally {
                $database->disconnect();
            }
            return [0];
        }
    }