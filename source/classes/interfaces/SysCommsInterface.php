<?php

    interface SysCommsInterface {
        public function sendMessage() : Bool;
        public function addToCurlDataArray(string $key, mixed $value) : void;
    }
