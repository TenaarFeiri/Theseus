<?php
    /**
     * Class RptoolController
     * 
     * This controller receives generic RP tool requests and processes them. This is typically just dialogs and menus.
     */
    class RptoolController {

        public function __construct() {
            require_once theseusPath() . "source/classes/parsers/XMLHandler.php";
            Data::setXml(new XMLHandler(theseusPath() . xmlStringsPath())); // Set the XMLHandler.
            if(!Data::getXml()) {
                throw new RuntimeException("XMLHandler not provided.");
            }
        }

    }