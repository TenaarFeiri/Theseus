<?php 
    declare(strict_types=1);
    require_once theseusPath() . "source/classes/interfaces/SysCommsInterface.php";
    /**
     * Communicator sending data to the client system.
     * 
     */
    class SysComms implements SysCommsInterface {
        private string $uuid;
        private ?CurlHandle $curl = null;
        private array $parameters = [];
        public function __construct(?array $parameters = null) {
            if(!$parameters) { // We don't want to send accidental commands to random tools so...
                // If the UUID is empty, throw an error.
                throw new RuntimeException(Data::getXml()->getString("//errors/noParamsProvidedToClass") . __CLASS__);
            }
            if(!isset($parameters['uuid']) || empty($parameters['uuid'])) {
                // If the UUID is empty, throw an error.
                throw new RuntimeException(Data::getXml()->getString("//errors/noParamsProvidedToClass") . __CLASS__);
            }
            $this->parameters = $parameters;
            $this->uuid = $this->parameters['uuid'];
            $this->parameters["curlData"] = []; // Initialize the curl data array.
        }

        public function __destruct() {
            if (isset($this->curl) && $this->curl instanceof CurlHandle) {
                curl_close($this->curl);
                $this->curl = null;
            }
        }

        /**
         * Adds a key-value pair to the curl data array for transmission to the client system.
         * 
         * @param string $key The key to add.
         * @param string $value The value to add.
         */
        public function addToCurlDataArray(string $key, mixed $value) : void {
            $this->parameters["curlData"][$key] = $value;
        }

        /**
         * Sends a message to the client system.
         * 
         * @param string $whatPing Ping indicator. Only change if you have a reason to.
         * @return bool True if the message was sent.
         * @throws RuntimeException If the curlData is empty, in which case you did not use addToCurlDataArray.
         */
        public function sendMessage(string $whatPing = "ping") : Bool {
            if(empty($this->parameters["curlData"]) || !is_array($this->parameters["curlData"]) || count($this->parameters["curlData"]) < 1) {
                throw new RuntimeException(Data::getXml()->getString("//errors/noDataProvided"));
            }
            // Send the message to the client system.
            // Single encode with wrapper
            $this->parameters["curlData"] = json_encode([
                $whatPing => $this->parameters["curlData"]  // Raw array
            ]);
            if(THESEUS_DEBUG) {
                echo PHP_EOL, "Sending: ", PHP_EOL;
                var_dump($this->parameters["curlData"]);
            }
            if(!$this->prepareCurl()) {
                return false;
            }
            $error = [];
            $x = 0;
            $max = 1;
            do {
                $response = curl_exec($this->curl);
                if($response === false) {
                    $error[] = curl_error($this->curl);
                }
                else {
                    break;
                }
                $x++;
            } while($x < $max);
            if($response === false) { // If we get here, we failed to send the message.
                curl_close($this->curl);
                throw new RuntimeException(Data::getXml()->getString("//errors/systemCurlFailedToSend") . "Curl error: " . print_r($error, true));
            }
            if(THESEUS_DEBUG) {
                echo PHP_EOL, "Response: ", PHP_EOL;
                var_dump($response);
            }
            curl_close($this->curl);
            $this->curl = null;
            return true;
        }

        private function prepareCurl() : Bool {
            if(!$this->uuid) {
                throw new RuntimeException(Data::getXml()->getString("//errors/noParamsProvidedToClass") . __CLASS__ . ". This error should not have occurred in prepcurl.");
            }
            if(empty($this->parameters)) {
                throw new RuntimeException(Data::getXml()->getString("//errors/noDataProvided"));
            }
            if(!isset($this->parameters["url"]) || empty($this->parameters["url"])) {
                $database = new DatabaseHandler();
                $database->connect();
                $select = ["*"];
                $where = ["player_uuid" => $this->uuid];
                $userData = $database->select("players", $select, $where);
                if(empty($userData)) {
                    throw new RuntimeException(Data::getXml()->getString("//errors/userNotFound"));
                }
                $database->disconnect();
                $this->parameters['url'] = $userData[0]["player_titler_url"];
            }
            if (empty($this->parameters['url']) || $this->parameters['url'] === '' || !filter_var($this->parameters['url'], FILTER_VALIDATE_URL) || $this->parameters['url'] == null) {
                if ($GLOBALS["debugMode"]) {
                    var_dump("Invalid or empty URL: " . $this->parameters['url']);
                }
                return false;
            }
            // Initiate CURL.
            if($this->curl instanceof CurlHandle) {
                curl_close($this->curl);
                $this->curl = null;
            }
            $this->curl = curl_init($this->parameters['url']);
            if(!$this->curl) {
                throw new RuntimeException("Curl failed to initialize.");
            }
            curl_setopt_array($this->curl, [
                CURLOPT_POST => true,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_POSTFIELDS => $this->parameters["curlData"],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FAILONERROR => true
            ]);
            return true;
        }
    }

