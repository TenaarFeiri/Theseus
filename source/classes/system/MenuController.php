<?php

    class MenuController {
        private string $dialogText;
        private array $dialogItems = []; // number of elements must match the dialogOptions array
        private array $dialogOptions = [];
        private bool $isTextbox = false;
        private bool $validated = false;
        private string $module; // The module that this dialog is intended to call from the client system.
        private string $commandResponse;
        private const VALID_FUNCTIONS = ["cancel"];
        private SysComms $sysComms;

        public function __call(string $name, array $args) {
            if($name !== "confirmDialog") {
                $this->validated = false;
            }
            if (method_exists($this, $name)) {
                return call_user_func_array([$this, $name], $args);
            }
        }

        ////////////////// Interaction Methods from the RP Tool ///////////////////

        public function process() : ?Array {
            // First of all, if module is not set to "character", how did we get here?
            $parameters = Data::getParams();
            if($parameters["module"] != "menu") {
                return null;
            }
            // Require syscomms, as we're using it if we're processing menus.
            require_once THESEUS_CLASS_PATH . "system/SysComms.php";
            $this->sysComms = new SysComms();
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
        

        private function cancel() : void {
            exit("CANCELED!");
        }

        /////////////// Menu Controller Methods ///////////////

        private function mainMenu() : ?Array {
            Data::loadMenus(); // Load the menus into memory.
            $dialogText = Data::getXml()->getString("//menu/mainMenu/title") . "\n" . Data::getXml()->getString("//menu/mainMenu/description");
            $this->setDialogText($dialogText);
            $items = [];
            $choices = [];
            foreach(Data::$menus->MainMenu as $key => $value) {
                $items[] = $key;
                $choices[] = $value->action;
            }
            $this->setModule("menu");
            exit("HELLO"); // You stopped here.
            return [1]; // Report success to the master controller.
        }

        private function characterMenu() : ?Array {
            Data::setParams(["module" => "character", "function" => "showCharList"]);
            return [1, "status" => "runNewCommand"];
        }

        ///////////////////////////////////////////////////////////////////////////
        /**
         * Sets which module this menu is intended to call.
         * 
         */
        public function setModule(string $module) : void {
            $this->module = $module;
        }

        public function setDialogText(string $dialogText) : void {
            require_once THESEUS_CLASS_PATH . "parsers/StringParser.php";
            $parser = new StringParser();
            $this->dialogText = $parser->truncateText($dialogText);
        }

        public function setDialog(array $dialogItems = [], array $dialogOptions = []) : void {
            if (count($dialogItems) !== count($dialogOptions)) {
                throw new RuntimeException(Data::getXml()->getString("//errors/dialogItemsAndOptionsMismatch"));
            }
            if(count($dialogItems) < 1 || count($dialogOptions) < 1) {
                throw new RuntimeException(Data::getXml()->getString("//errors/dialogItemsAndOptionsEmpty"));
            } 
            if($this->isTextbox) {
                return;
            }
            $this->dialogItems = $dialogItems;
            $this->dialogOptions = $dialogOptions;
        }

        public function getString(string $string) : string { // Quick abstraction to get strings from the XML file.
            return Data::getXml()->getString($string);
        }

        public function isTextBox(bool $isTextbox) : void {
            $this->isTextbox = $isTextbox;
            if($this->isTextbox === 1 && (count($this->dialogItems) > 0 || count($this->dialogOptions) > 0)) {
                $this->dialogItems = [];
                $this->dialogOptions = [];
            }
        }

        private function orderRowsForDialog(array $array) : array {
            $rowLength = 3;
            $maxEntries = 12;
            $mainEntries = 9;
            // Extract special row
            $lastRow = array_slice($array, -$rowLength);
            $isSpecialRow = (
                count($lastRow) === $rowLength &&
                $lastRow[1] === "Cancel" &&
                (
                    ($lastRow[0] === "--" && $lastRow[2] === "--") ||
                    ($lastRow[0] === "<<" && $lastRow[2] === "--") ||
                    ($lastRow[0] === "--" && $lastRow[2] === ">>") ||
                    ($lastRow[0] === "<<" && $lastRow[2] === ">>")
                )
            );
            
            // Process main array
            $mainArray = $isSpecialRow ? 
                array_slice($array, 0, -$rowLength) : 
                array_slice($array, 0, $mainEntries);
            
            // Build special row
            $specialRow = ["--", "Cancel", "--"];
            if ($isSpecialRow) {
                $specialRow = $lastRow;
            }
            
            // Handle navigation buttons
            if (in_array("<<", $mainArray)) {
                $specialRow[0] = "<<";
                $mainArray = array_values(array_diff($mainArray, ["<<"]));
            }
            if (in_array(">>", $mainArray)) {
                $specialRow[2] = ">>";
                $mainArray = array_values(array_diff($mainArray, [">>"]));
            }
            
            // Create full grid with proper positioning
            $gridArray = array_fill(0, $mainEntries, "--");
            $nonEmpty = array_filter($mainArray, function($value) {
                return $value !== "--";
            });
            $nonEmpty = array_values($nonEmpty);
            
            // Place non-empty entries in correct positions (top to bottom)
            for ($i = 0; $i < count($nonEmpty); $i++) {
                $gridArray[$i] = $nonEmpty[$i];
            }
            $newGrid = array_merge(array_slice($gridArray, 6, 3), array_slice($gridArray, 3, 3), array_slice($gridArray, 0, 3));
            // Combine arrays with special row first
            return array_merge($specialRow, $newGrid);
        }

        public function setCommandResponseCommand(string $commandResponse) {
            $this->commandResponse = $commandResponse;
        }

        public function getFinishedDialog(string $arrayKey = null) : array {
            if($this->confirmDialog()) {
                // Make sure that our dialog options will render in human-readable order.
                $this->dialogItems = $this->orderRowsForDialog($this->dialogItems);
                $this->dialogOptions = $this->orderRowsForDialog($this->dialogOptions);
                $output = [
                    "dialogText" => $this->dialogText,
                    "dialogItems" => implode(",", $this->dialogItems),
                    "dialogOptions" => implode(",", $this->dialogOptions),
                    "isTextbox" => $this->isTextbox ? 1 : 0,
                    "module" => $this->module
                ];
                if(!empty($this->commandResponse)) {
                    $output["responseCommand"] = $this->commandResponse;
                }
                return $arrayKey ? [$arrayKey => $output] : $output;
            }
            throw new RuntimeException(Data::getXml()->getString("//errors/dialogNotComplete"));
        }

        public function confirmDialog() : bool {
            if($this->isTextbox && 
                (
                    count($this->dialogItems) == 0 && 
                    count($this->dialogOptions) == 0) && 
                    !empty($this->dialogText)) {
                    $this->validated = true;
                    return $this->validated;
            }
            if(!$this->isTextbox) {
                if((count($this->dialogItems) == count($this->dialogOptions)) && count($this->dialogItems) > 0 &&
                    !empty($this->dialogText) && !empty($this->module)) {
                    $this->validated = true;
                    return $this->validated;
                }
            }
            return false;
        }
    }