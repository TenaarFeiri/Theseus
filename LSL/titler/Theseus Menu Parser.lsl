/*
    Menu Parser
    
    todo:
        - Make a menu building option that just works.
*/
string lsdPass = "protoserv"; // Access protected LSD entries.
string module = "menu";
list dataKey; // Do not touch, loaded every time a link_message is retrieved to keep updated keys.
string cmd = "";
list menuItems;
list menuOptions;
string menuText;

default
{
    state_entry()
    {
        
    }

    touch_start(integer total_number)
    {
        llMessageLinked(LINK_SET, (integer)llLinksetDataReadProtected("httpServerNum", lsdPass), "menu|mainMenu|page=1", NULL_KEY);
    }
    
    link_message(integer sender, integer num, string message, key id) {
        if(num == 1 && message == "dialogs") {
            dataKey = llParseStringKeepNulls(llLinksetDataReadProtected("dataKey", lsdPass), [","], []);
            llLinksetDataWriteProtected("dialog", 
                llJsonGetValue(llLinksetDataRead(llList2String(dataKey,1)), ["ping"]
            ), lsdPass);
            if(llJsonGetValue( llLinksetDataReadProtected("dialog", lsdPass), ["scriptFunc"]) != message) {
                llLinksetDataWriteProtected("dialog", "0", lsdPass); // Empty when not in use.
                return; // Do nothing if we really don't match.
            }
            llMessageLinked(LINK_SET, (integer)llLinksetDataReadProtected("httpServerNum", lsdPass), "confirm", "");
            menuItems = llParseStringKeepNulls(
                llJsonGetValue(
                    llLinksetDataReadProtected("dialog", lsdPass), ["data","dialogItems"]
                ), [","], []
            );
            menuOptions = llParseStringKeepNulls(llJsonGetValue(
                    llLinksetDataReadProtected("dialog", lsdPass), ["data","dialogOptions"]
                ), [","], []);
            menuText = llUnescapeURL(llJsonGetValue(llLinksetDataReadProtected("dialog", lsdPass), ["data","dialogText"]));
            llDialog(llGetOwner(), menuText, menuItems, -1);
        }
    }
}
