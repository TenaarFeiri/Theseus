<?php

    class MenuController {
        private string $dialogText;
        private array $dialogItems = []; // number of elements must match the dialogOptions array
        private array $dialogOptions = [];
        private bool $isTextbox = false;
        private bool $validated = false;
        private string $module; // The module that this dialog is intended to call from the client system.

        public function __call(string $name, array $args) {
            if($name !== "confirmDialog") {
                $this->validated = false;
            }
            if (method_exists($this, $name)) {
                return call_user_func_array([$this, $name], $args);
            }
        }

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

        public function getFinishedDialog() : array {
            if($this->confirmDialog()) {
                return [
                    "dialogText" => $this->dialogText,
                    "dialogItems" => implode(",", $this->dialogItems),
                    "dialogOptions" => implode(",", $this->dialogOptions),
                    "isTextbox" => $this->isTextbox ? 1 : 0,
                    "module" => $this->module
                ];
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