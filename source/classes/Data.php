<?php 
    declare(strict_types=1);
    /**
     * Class ParameterStorage
     * 
     * Master class for any data-related methods.
     * 
     * @method static array get() Returns stored parameters or initializes from POST/GET
     * @method static void set(array $newParams) Sets new parameters in storage
     * @method static XMLHandler getXml() Returns stored XMLHandler
     * @method static void setXml(XMLHandler $xml) Sets new XMLHandler in storage
    */
    class Data {
        private static ?array $params = null;
        private static ?XMLHandler $xml = null;
        public static ?stdClass $menus = null;
        
        public static function getParams(): array {
            if (self::$params === null) {
                self::$params = !empty($_POST) ? $_POST : $_GET;
            }
            return self::$params;
        }
        
        public static function setParams(array $newParams): void {
            self::$params = $newParams;
        }

        public static function updateParam(string $key, string $value): void {
            self::$params[$key] = $value;
        }

        public static function deleteParam(string $key): void {
            unset(self::$params[$key]);
        }

        public static function getXml(): ?XMLHandler {
            return self::$xml;
        }
        
        public static function setXml(XMLHandler $xml): void {
            self::$xml = $xml;
        }

        public static function logAction(string $action, string $uuid = null, string $username = null): void {
            // This needs a lot more work, finish later
            $log = fopen(THESEUS_PATH . 'source/data/logs/log.txt', 'a');
            fwrite($log, date('Y-m-d H:i:s') . ' - ' . $action . PHP_EOL);
            fclose($log);
        }

        // Methods related to menus
        public static function loadMenus(): void {
            $path = THESEUS_PATH . 'source/data/json/Menus.json';
            if (!file_exists($path)) {
                http_response_code(400);
                throw new RuntimeException("Menus file not found.");
            }
            $menus = file_get_contents($path);
            self::$menus = json_decode($menus);
        }
    }