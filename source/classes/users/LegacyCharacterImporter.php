<?php
    require_once theseusPath() . "source/classes/interfaces/LegacyCharacterImporterInterface.php";

    /**
     * Handles the importation of legacy characters from an old database system
     * 
     * @implements LegacyCharacterImporterInterface
     */
    class LegacyCharacterImporter implements LegacyCharacterImporterInterface {
        /** @var string|null UUID of the player whose characters are being imported */
        private $uuid;

        /**
         * Creates a new LegacyCharacterImporter instance
         * 
         * @param string|null $uuid The UUID of the player
         * @throws RuntimeException If UUID is null or empty
         */
        public function __construct(?string $uuid = null) {
            if(!$uuid) {
                throw new RuntimeException(Data::getXml()->getString("//errors/noParamsProvidedToClass") . __CLASS__ . " (empty uuid)");
            }
            $this->uuid = $uuid;
        }

        /**
         * Imports characters from the legacy database to the new system
         * 
         * @return bool True if import was successful
         * @throws RuntimeException If no user is found, UUIDs don't match, or import fails
         */
        public function importCharacters() : Bool {
            $database = new DatabaseHandler();
            $database->connect();
            $select = ["*"];
            $where = ["player_uuid" => $this->uuid];
            $result = $database->select("players", $select, $where);
            if(empty($result)) {
                throw new RuntimeException(Data::getXml()->getString("//errors/noImportUserFound"));
            }
            $userData = $result[0];
            $database->disconnect();
            $database->connect("rp_tool");
            $select = ["*"];
            $where = ["id" => $userData['player_legacy_id']];
            $result = $database->select("users", $select, $where);
            if(empty($result)) {
                throw new RuntimeException(Data::getXml()->getString("//errors/noImportUserFound"));
            }
            $legacyData = $result[0];
            if(THESEUS_DEBUG) {
                var_dump($userData);
                var_dump($legacyData);
            }
            if($userData['player_uuid'] != $legacyData['uuid'] || $userData['player_legacy_id'] != $legacyData['id']) {
                $database->disconnect();
                throw new RuntimeException(Data::getXml()->getString("//errors/uuidImportMismatch"));
            }
            $where = ["user_id" => $legacyData['id']];
            $legacyCharacters = $database->select("rp_tool_character_repository", $select, $where);
            $database->disconnect();
            if(empty($legacyCharacters)) { // If there are no characters to import, we'll just set the current char to 0.
                $database->connect();
                $database->update("players", ["player_current_character" => 0], ["player_uuid" => $this->uuid]);
                return true; // No characters to import, all's well.
            }
            $legacyCharacters = array_filter($legacyCharacters, function($character) {
                return $character['deleted'] == 0;
            });
            $newCharacters = [];
            foreach($legacyCharacters as $character) {
                $constants = explode("=>", $character['constants']);
                $titles = explode("=>", $character['titles']);
                $name = $titles[0];
                $titles = array_map(function($constant, $title) {
                    if($title == "@invis@") {
                        return "";
                    }
                    $title = str_replace('$p', "\n", $title); // Replace $p tags with newlines
                    $title = preg_replace('/\$[a-zA-Z]\b/', '', $title); // Remove single letter $ tags
                    return $constant . " " . $title;
                }, array_slice($constants, 3), array_slice($titles, 3));
                $description = implode("\n", $titles);
                $rgbValues = explode(',', explode('=>', $character['settings'])[0]);
                $normalizedRGB = array_map(function($value) {
                    return number_format((float)$value / 255, 1);
                }, $rgbValues);
                $color = '<' . implode(',', $normalizedRGB) . '>';
                $newCharacters[] = [
                    "player_id" => $userData['player_id'],
                    "character_name" => $name,
                    "character_description" => $description,
                    "color" => $color,
                    "legacy" => $character['character_id']
                ];
            }
            if(count($newCharacters) != count($legacyCharacters)) {
                throw new RuntimeException(Data::getXml()->getString("characterImportMismatch"));
            }
            $database->connect();
            $database->beginTransaction();
            $values = [];
            $placeholders = [];
            foreach($newCharacters as $character) {
                $values[] = $character['player_id'];
                $values[] = $character['character_name'];
                $values[] = $character['character_description'];
                $values[] = $character['color'];
                $values[] = $character['legacy'];
                $placeholders[] = "(?, ?, ?, ?, ?)";
            }
            $sql = "INSERT INTO player_characters 
                    (player_id, character_name, character_description, color, legacy) 
                    VALUES " . implode(',', $placeholders);

            $success = $database->executeQuery($sql, $values);
            if(!$success) {
                $database->rollBack();
                throw new RuntimeException(Data::getXml()->getString("characterImportFailed"));
            }
            $database->commit();
            $database->disconnect();
            return true;
        }
    }
