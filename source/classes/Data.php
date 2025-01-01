<?php 
    declare(strict_types=1);
    /**
     * Class ParameterStorage
     * 
     * Master class for storing and retrieving parameters and data objects. Also includes logging.
     * 
     * @method static array get() Returns stored parameters or initializes from POST/GET
     * @method static void set(array $newParams) Sets new parameters in storage
     * @method static XMLHandler getXml() Returns stored XMLHandler
     * @method static void setXml(XMLHandler $xml) Sets new XMLHandler in storage
    */
    class Data {
        private static ?array $params = null;
        private static ?XMLHandler $xml = null;
        
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
    }