integer linkMsgNum = 0;
string systemName = "Theseus RP Tool";
string theseus = "https://hardy-simply-coral.ngrok-free.app/starfall/theseus/TheseusMasterController.php?"; // Theseus' URL.
integer debug = TRUE;
integer waiting;
string stopError;
key urlRequest;
key sendUrl;
key pingTheseus;
integer sendUrlRetries = 0;
integer sendUrlMaxRetries = 3;
list httpParams = [
    HTTP_METHOD, "POST", 
    HTTP_MIMETYPE, "application/x-www-form-urlencoded", 
    HTTP_BODY_MAXLENGTH, 16384, 
    HTTP_VERIFY_CERT, FALSE,
    HTTP_CUSTOM_HEADER, "X-System-Name", systemName
];
list dataKey = [ // List of keys in the Linkset Data structure.
    "url", // URL Storage
    "ping" // ping received, should contain json object
];
list scripts = [ // List of scripts in the linkset.
    "Theseus Character Parser",
    "Theseus Menu Parser"
];
resetSystem() {
    llLinksetDataReset();
    integer length = llGetListLength(scripts);
    integer i = 0;
    do {
        if(llGetInventoryType(llList2String(scripts, i)) == INVENTORY_SCRIPT) {
            llResetOtherScript(llList2String(scripts, i));
        }
        ++i;
    } while(i < length);
}
key requestTheseus(string module, string function, string data) {
    string request = "module=" + module + "&function=" + function + "&" + data;
    if(debug) {
        llOwnerSay("Requesting Theseus: " + request);
    }
    return llHTTPRequest(theseus, httpParams, request);
}
default {
    state_entry() {
        stopError = "";
        if(llGetAttached()) {
            //llSetLinkAlpha(LINK_SET, 0.0, ALL_SIDES);
        }
        resetSystem();
        llLinksetDataWriteProtected("dataKey", llDumpList2String(dataKey, ","), "protoserv");
        llLinksetDataWriteProtected("httpServerNum", "0", "protoserv");
        llSleep(3); // Sleep for 3 seconds to allow other scripts to initialize.
        urlRequest = llRequestSecureURL();
    }

    linkset_data(integer num, string name, string value) {
        if(num == LINKSETDATA_RESET && debug) {
            llOwnerSay("Linkset data reset.");
        }
    }
    
    timer() { // Track if we're waiting for a script to accept response.
        llSetTimerEvent(0);
        llLinksetDataWrite("ping", "0"); // Wipe the ping.
        if(waiting == 2) {
            llDialog(llGetOwner(), "Script failed to respond. Please inform staff of what you were doing when this happened.", ["OK"], -1);
        }
        waiting = FALSE;
    }
    
    link_message(integer sender, integer num, string message, key id) {
        if(num == linkMsgNum) {
            // Do things here if message is intended for this script.
            if(message == "confirm") { // Confirm that recipient script has received its data.
                llSetTimerEvent(0);
                llLinksetDataWrite(llList2String(dataKey, 1), "0"); // Wipe "ping" to conserve memory.
                waiting = FALSE;
                return;
            }
            if(!waiting) {
                list cmds = llParseStringKeepNulls(message, ["|"], []);
                llSetTimerEvent(10);
                waiting = TRUE;
                pingTheseus = requestTheseus(llList2String(cmds, 0), llList2String(cmds, 1), llList2String(cmds,2));
            } else {
                if(waiting != 2) {
                    waiting = 2;
                    llDialog(llGetOwner(), "Waiting for scripts to confirm received data, one moment...", ["OK"], -1);
                }
            }
        } else {
            // Otherwise process commands here.
            
        }
    }
    
    http_request(key requestId, string method, string body) {
        if(requestId == urlRequest) {
            if(method == URL_REQUEST_DENIED) {
                llOwnerSay("Could not obtain a URL for Theseus. Is the sim out of URLs maybe?");
                state stopped;
            }
            llLinksetDataWrite(llList2String(dataKey, 0), body);
            sendUrl = requestTheseus("user", "checkUser", "url=" + body);
        } else {
            if(debug) {
                llOwnerSay(body);
            }
            llHTTPResponse(requestId, 200, "OK");
            string writeToKey = llList2String(dataKey, 1);
            llLinksetDataWrite(writeToKey, body);
            // Then pass instructions to other scripts to check what they're supposed to do.
            llMessageLinked(LINK_SET, 1, llJsonGetValue(llLinksetDataRead(writeToKey), [writeToKey, "scriptFunc"]), "");
        }
    }
    
    http_response(key response, integer status, list meta, string body) {
        if(response == sendUrl) {
            if(status == 400) {
                if(debug) {
                    llOwnerSay("ERROR CODE: " + (string)status);
                    llOwnerSay(body);
                }
            }
            else if(status != 200 && status != 201) {
                llSleep(1.0); // Sleep for 1 second to wait for a resolution
                if(sendUrlRetries < sendUrlMaxRetries) {
                    ++sendUrlRetries;
                    // Do the thing here.
                } else {
                    state stopped;
                }
            } else {
                sendUrlRetries = 0;
                if(body == "0") {
                    stopError = "Server comms established, but URL storage operation failed.";
                    state stopped; // Go to stopped state.
                    return;
                }
            }
        } else if(response == pingTheseus) {
            if(status == 400) {
                if(debug) {
                    llOwnerSay("ERROR CODE: " + (string)status);
                    llOwnerSay(body);
                }
            }
            if(status != 200 && status != 201) {
                stopError = "Theseus is not responding. Tell the admins.";
                state stopped;
            }
        }
    }
    
    changed(integer change) {
        if(change & CHANGED_OWNER) {
            llResetScript();
        }
    }
}

state stopped {
    state_entry() {
        llMessageLinked(LINK_SET, 0, "::STOP::", ""); // Tell all scripts that we are stopped & put them in a stop state.
        llOwnerSay("STOP ERROR!!\nTouch the titler to reset and try again.\n\n");
        llSetLinkAlpha(LINK_SET, 1.0, ALL_SIDES);
        if(sendUrlRetries >= 3) {
            llOwnerSay("This was likely due to server failing to respond to the new URL. Tell an admin.");
        }
        if(stopError != "") {
            llOwnerSay("Error message: " + stopError);
        }
    }
    
    changed(integer change) {
        if(change & CHANGED_OWNER) {
            llResetScript();
        }
    }
    
    touch_end(integer touched) {
        if(llDetectedKey(0) == llGetOwner()) {
            llResetScript();
        }
    }
}
