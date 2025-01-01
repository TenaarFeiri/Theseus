integer scriptNum = 1;
string lsdPass = "protoserv";
string systemName = "Theseus RP Tool";
string theseus = "https://hardy-simply-coral.ngrok-free.app/starfall/theseus/TheseusMasterController.php?"; // Theseus' URL.
string stopError = "";
list dataKey;
integer debug = TRUE;
list commands = []; // Build dynamically a strided list, key, command.
list textRings;

// Functions
string parseCommands() {
    string command = llList2String(commands, 0);
    commands = llDeleteSubList(commands, 0, 0);
    return command;
}

setup() {
    // Scan the linkset for prims with the name format ring.x where x is a number.
    // Add to strided list, format: ring name, link number
     llSetLinkPrimitiveParamsFast(LINK_SET, [PRIM_TEXT, "", <1.0, 1.0, 1.0>, 1.0]);
    integer i = 0;
    do {
        string name = llGetLinkName(i);
        if(llSubStringIndex(name, "ring.") == 0) {
            textRings += [name, i];
        }
        ++i;
    } while(i <= llGetNumberOfPrims());
}

integer calculateExtraNewlines(string text) {
    list lines = llParseString2List(text, ["\n"], []);
    return llGetListLength(lines) - 1;
}

integer getLinkNumber(string name) {
    if(llListFindList(textRings, [name]) == -1) {
        return -1;
    } else {
        return llList2Integer(textRings, llListFindList(textRings, [name]) + 1);
    }
}

string appendNewlines(string text, integer extraLines, integer offset) {
    integer baseNewlines = 5;
    string newlines = "";
    integer i;
    for(i = 0; i < extraLines; ++i) {
        newlines += "\n ";
    }
    return text + newlines;
}

parseText() {
    // Extract data
    string name = llUnescapeURL(llJsonGetValue(llLinksetDataRead("character"), ["name"]));
    vector color = (vector)llJsonGetValue(llLinksetDataRead("character"), ["color"]);
    string description;
    string heart = "💖";
    string hp = heart + " 40" + "/" + "40";
    // Process tags
    string tags = llJsonGetValue(llLinksetDataRead("character"), ["tags"]);
    tags = "<" + llDumpList2String(llList2List(llParseString2List(tags, [","], []), 0, 1), ", ") + ">";
    // Handle AFK/OOC status
    integer afk_ooc = (integer)llJsonGetValue(llLinksetDataRead("character"), ["afk_ooc"]);
    if(afk_ooc == 1) {
        description = "[OOC]";
        heart = "";
        hp = "";
        tags = "";
    }
    else if(afk_ooc == 2) {
        description = "[AFK]";
        heart = "";
        hp = "";
        tags = "";
    }
    else { 
        description = llUnescapeURL(llJsonGetValue(llLinksetDataRead("character"), ["desc"]));
    }
    // Calculate description newlines
    integer extraLines = calculateExtraNewlines(description);
    
    // Add newlines to upper elements based on description length
    string nameWithNewlines = name;
    string tagsWithNewlines = tags;
    string hpWithNewlines = hp;
    
    integer i;
    for(i = 0; i < extraLines; ++i) {
        nameWithNewlines += "\n ";
        tagsWithNewlines += "\n ";
        hpWithNewlines += "\n ";
    }
    // Count HP for safety.
    extraLines = calculateExtraNewlines(hp);
    for(i = 0; i < extraLines; ++i) {
        hpWithNewlines += "\n ";
    }
    // Then count tags.
    extraLines = calculateExtraNewlines(tags);
    for(i = 0; i < extraLines; ++i) {
        nameWithNewlines += "\n ";
    }
    
    // Set text for each ring
    llSetLinkPrimitiveParamsFast(getLinkNumber("ring.4"), [PRIM_TEXT, nameWithNewlines + "\n \n \n ", color, 1.0]);
    if(!afk_ooc) { 
        llSetLinkPrimitiveParamsFast(getLinkNumber("ring.3"), [PRIM_TEXT, tagsWithNewlines + "\n \n ", color, 1.0]);
    } else { // Description will be AFK/OOC status.
        llSetLinkPrimitiveParamsFast(getLinkNumber("ring.3"), [PRIM_TEXT, description + "\n \n ", color, 1.0]);
    }
    if(!afk_ooc) {
        llSetLinkPrimitiveParamsFast(getLinkNumber("ring.2"), [PRIM_TEXT, hpWithNewlines + "\n ", color, 1.0]);
    } else {
        llSetLinkPrimitiveParamsFast(getLinkNumber("ring.2"), [PRIM_TEXT, "", color, 1.0]);
    }
    if(!afk_ooc) {
        llSetLinkPrimitiveParamsFast(getLinkNumber("ring.1"), [PRIM_TEXT, description, color, 1.0]);
    } else {
        llSetLinkPrimitiveParamsFast(getLinkNumber("ring.1"), [PRIM_TEXT, "", color, 1.0]);
    }
    
    llLinksetDataWrite("name", name);
}



default {
    state_entry() {
        setup();
        if(debug) {
            llOwnerSay(llList2CSV(textRings));
        }
    }

    link_message(integer sender_num, integer num, string str, key id) {
        if(num == scriptNum && str == "characters") {
            dataKey = llParseStringKeepNulls(llLinksetDataReadProtected("dataKey", lsdPass), [","], []);
            string command = llJsonGetValue(llLinksetDataRead(llList2String(dataKey, 1)), [llList2String(dataKey, 1), "cmd"]);
            if(command == "loadCharacter") {
                llLinksetDataWrite("character", (llJsonGetValue(llLinksetDataRead(llList2String(dataKey, 1)), [llList2String(dataKey, 1), "data"])));
                llMessageLinked(LINK_SET, (integer)llLinksetDataReadProtected("httpServerNum", lsdPass), "confirm", "");
                parseText();
            }
        }
    }
}

state stopped {
    state_entry() {
        llOwnerSay("Stopped: " + stopError);
    }
}