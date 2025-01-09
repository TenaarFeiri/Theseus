/*
    Menu Parser
    
    todo:
        - Make a menu building option that just works.
*/
string lsdPass = "protoserv"; // Access protected LSD entries.
string thisModule = "menu";
list dataKey; // Do not touch, loaded every time a link_message is retrieved to keep updated keys.
string module = "";
string responseCommand = ""; // Keeps a string with the response command, response=csv1,csv2,etc.
list menuItems;
list menuOptions;
string menuText;
integer menuChannel;
integer menuChannelListener;
float timeout = 120;

string updateResponseCommand(string data) {
    return llDumpList2String(llParseStringKeepNulls(responseCommand, ["%s"], []) + [data], "");
}

integer generateChannel(key ID, integer App) {
    return 0x80000000 | ((integer)("0x"+(string)ID) ^ App);
}

string getValue(string json, list keys, string pass) {
    if(pass == "") {
        return llJsonGetValue(llLinksetDataRead(json), keys);
    }
    return llJsonGetValue(llLinksetDataReadProtected(json, lsdPass), keys);
}

default
{
    state_entry()
    {
        
    }
    
    timer() {
        llSetTimerEvent(0);
        llListenRemove(menuChannelListener);
        llOwnerSay("Menu timed out.");
    }
    
    listen(integer c, string n, key id, string m) {
        if(c == menuChannel) {
            llListenRemove(menuChannelListener); // Remove the listen, we'll get a new channel from the server.
            llSetTimerEvent(0);
            string result;
            if(m != "--" && m != "<<" && m != ">>") {
                integer pos = llListFindList(menuItems, [m]);
                if(pos == -1) {
                    llOwnerSay("An error has occurred: Menu items out of bounds.");
                    return;
                }
                result = llList2String(
                    menuOptions,
                    pos
                );
            } else {
                result = m;
                if(result != "--") {
                    result = result + "&page=" + getValue("dialog", ["data","page"], lsdPass);
                }
            }
            responseCommand = updateResponseCommand(result);
            string out = module + "|" + responseCommand;
            llMessageLinked(LINK_SET, (integer)llLinksetDataReadProtected("httpServerNum", lsdPass), out, "");
        }
    }

    touch_start(integer total_number)
    {
        llMessageLinked(LINK_SET, (integer)llLinksetDataReadProtected("httpServerNum", lsdPass), thisModule + "|mainMenu|page=1", NULL_KEY);
    }
    
    link_message(integer sender, integer num, string message, key id) {
        if(num == 1 && message == "dialogs") {
            dataKey = llParseStringKeepNulls(llLinksetDataReadProtected("dataKey", lsdPass), [","], []);
            llLinksetDataWriteProtected("dialog", 
                llJsonGetValue(llLinksetDataRead(llList2String(dataKey,1)), ["ping"]
            ), lsdPass);
            if(getValue("dialog", ["scriptFunc"], lsdPass) != message) {
                llLinksetDataWriteProtected("dialog", "0", lsdPass); // Empty when not in use.
                return; // Do nothing if we really don't match.
            }
            llMessageLinked(LINK_SET, (integer)llLinksetDataReadProtected("httpServerNum", lsdPass), "confirm", "");
            menuItems = llParseStringKeepNulls(
                    getValue("dialog", ["data","dialogItems"], lsdPass), 
                    [","], []);
            menuOptions = llParseStringKeepNulls(
                    getValue("dialog", ["data","dialogOptions"], lsdPass), 
                    [","], []);
            menuText = llUnescapeURL(getValue("dialog", ["data","dialogText"], lsdPass));
            responseCommand = getValue("dialog", ["data","responseCommand"], lsdPass);
            module = getValue("dialog", ["data","module"], lsdPass);
            menuChannel = generateChannel(llGetOwner(), (integer)llFrand(999.0));
            llListenRemove(menuChannelListener);
            menuChannelListener = llListen(menuChannel, "", llGetOwner(), "");
            llDialog(llGetOwner(), menuText, menuItems, menuChannel);
        }
    }
}
