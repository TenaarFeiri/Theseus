<?php
    declare(strict_types=1);
    class StringParser
    {
        // Handles methods related to string parsing, like replacing wildcards and such.
        function __construct()
        {

        }

        public function truncateText(string $text = " ", int $limit = 254) : string {
            if (strlen($text) > $limit) {
                return substr($text, 0, $limit);
            }
            return $text;
        }

        function replacePlaceholders(array $replacement, string $placeholder) : String
        {
            return "";
        }

        function findAndExtract(array $find) : Array
        {
            // Find and extract words in the $find array, return these words as an array.
            exit();
        }
    }
