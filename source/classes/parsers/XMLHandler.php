<?php

    class XMLHandler {
        private static ?SimpleXMLElement $xml = null;

        public function __construct(string $xmlPath) {
            if (self::$xml === null) {
                $result = simplexml_load_file($xmlPath);
                if ($result === false) {
                    throw new RuntimeException("Failed to parse XML");
                }
                self::$xml = $result;
            }
        }

        public function getString(string $path): string {
            $result = self::$xml->xpath($path);
            return !empty($result) ? (string)$result[0] : '';
        }
    }